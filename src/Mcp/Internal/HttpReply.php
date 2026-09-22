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

            return is_array($decoded) && (isset($decoded['error']) || isset($decoded['result'])) ? $decoded : null;
        }

        if (str_starts_with($type, 'text/event-stream')) {
            return SseReader::find($this->body, $id);
        }
        if (!str_starts_with($type, 'application/json')) {
            throw new McpProtocolException(sprintf('MCP %s answered with an unsupported content type.', $this->method));
        }

        $decoded = json_decode($this->body, true);
        if (!is_array($decoded) || isset($decoded['method']) || ($decoded['id'] ?? null) !== $id) {
            throw new McpProtocolException(sprintf('MCP %s did not answer with the response to its request.', $this->method));
        }

        return $decoded;
    }
}
