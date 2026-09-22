<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Internal;

use CarmeloSantana\PHPAgents\Mcp\McpProtocolException;

/**
 * One of the HTTP answers HttpExchange returns rather than throws on: a 2xx, 400 or 404.
 *
 * message($id) finds the JSON-RPC message in the body. A body that is empty or all
 * whitespace has no message, whatever the status or content type. Otherwise:
 * - A 2xx body must be `application/json` holding the response to $id, or
 *   `text/event-stream` holding it somewhere (SseReader). Anything else is a protocol
 *   error, and an SSE stream without it is null here.
 * - A 400/404 body is read if it is a JSON-RPC response object (it has `error` or
 *   `result`), whatever its id; otherwise it has no message, which is a signal in
 *   itself — spec §2 makes an unrecognised 400 the trigger for the 2025-11-25 fallback.
 *
 * "Has `error` or `result`", and spec §2's "any message carrying `method`", are both read as
 * the key being present: array_key_exists, not isset. That is what the words mean on the
 * JSON-RPC wire — a message carrying `"method": null` does carry `method`, and a `result` of
 * null is a legal response — while isset() answers no to any key whose value is null, a
 * predicate spec §2 did not write.
 *
 * The two halves do not buy the same thing. On `method` the reading decides an outcome: a
 * `"method": null` frame is skipped rather than taken as the response, here and in SseReader,
 * and tests cover both. On `error`/`result` it decides nothing downstream that is yet visible
 * — for `{"result": null}` neither reading offers Task 12 a JSON-RPC error, and for
 * `{"error": null}` neither offers it an error object, so spec §2's 400 and 404 rules reach
 * the same verdict either way. It is written this way for reading one rule one way, not for a
 * consequence.
 *
 * @internal
 */
final class HttpReply
{
    /**
     * @param array<string, list<string>> $headers response header names lower-cased, as Symfony returns them
     */
    public function __construct(
        public readonly string $method,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)][0] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The JSON-RPC envelope in this body, or null when it carries none.
     *
     * Handing the decoded envelope back, rather than turning a JSON-RPC `error` object
     * into an McpRpcException here, is deliberate. That exception splices up to 200 bytes
     * of the server's own error text into its message, and a server can echo a configured
     * header value or the session id back in that text, so spec §2 (amendment 3,
     * 2026-09-21) has McpClient redact both before the exception is built. This class is
     * never handed McpServer::$headers, so it could not redact them. Task 12's McpClient
     * owns the redaction and the McpRpcException — do not move either down here.
     *
     * The McpProtocolException messages thrown below are formatted from $this->method
     * alone; no part of the body reaches them.
     *
     * @return array<array-key, mixed>|null
     */
    public function message(int $id): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }
        $type = strtolower($this->header('content-type') ?? '');

        if (!$this->isSuccess()) {
            $decoded = json_decode($this->body, true);

            return is_array($decoded) && (array_key_exists('error', $decoded) || array_key_exists('result', $decoded)) ? $decoded : null;
        }

        if (str_starts_with($type, 'text/event-stream')) {
            return SseReader::find($this->body, $id);
        }
        if (!str_starts_with($type, 'application/json')) {
            throw new McpProtocolException(sprintf('MCP %s answered with an unsupported content type.', $this->method));
        }

        $decoded = json_decode($this->body, true);
        if (!is_array($decoded) || array_key_exists('method', $decoded) || ($decoded['id'] ?? null) !== $id) {
            throw new McpProtocolException(sprintf('MCP %s did not answer with the response to its request.', $this->method));
        }

        return $decoded;
    }
}
