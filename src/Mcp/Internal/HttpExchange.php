<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Internal;

use CarmeloSantana\PHPAgents\Mcp\McpAuthException;
use CarmeloSantana\PHPAgents\Mcp\McpProtocolException;
use CarmeloSantana\PHPAgents\Mcp\McpRedirectException;
use CarmeloSantana\PHPAgents\Mcp\McpServer;
use CarmeloSantana\PHPAgents\Mcp\McpTransportException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One POST of one JSON-RPC message to McpServer::$url. Spec §2 confines the client to
 * that single URL, and post() hands McpServer::$url to the injected client unchanged
 * rather than deriving any other.
 *
 * The request asks for no redirects (`max_redirects: 0`), for $timeout as both the
 * idle timeout and `max_duration`, and for an `on_progress` callback that aborts past
 * $maxResponseBytes. Spec §2 ("Limits and redirects") requires the limits to hold even
 * when a host wrapper overrides those options — the HTTP client is injected precisely
 * so a host can supply its own egress — so the outcome is checked here as well: any 3xx
 * throws McpRedirectException whether or not the client tried to follow it, and the body
 * is measured again after it has been read.
 *
 * - 2xx, 400 and 404 come back as an HttpReply, because spec §2 reads protocol meaning
 *   into those bodies (version fallback, stale sessions, JSON-RPC errors).
 * - 401/403 throw McpAuthException.
 * - Every other status throws McpTransportException.
 * - Every message built in this class is formatted from $method, the HTTP status and the
 *   configured byte cap, and from nothing else: no URL, header value, session id or
 *   server-supplied text reaches one. That matters because a transport failure's own
 *   message does quote the URL — symfony/http-client v8.1.7 raises "Idle timeout reached
 *   for "<url>"." — and reason() below drops it.
 *
 * Neither this class nor HttpReply builds an McpRpcException. That exception splices the
 * server's own error text into its message, and a server can echo a configured header
 * value or the session id back in that text, so spec §2 (amendment 3, 2026-09-21) puts
 * the redaction in McpClient, the one object holding both. HttpReply hands the decoded
 * JSON-RPC envelope back instead, leaving that seam open. Task 12's McpClient owns the
 * redaction and the McpRpcException; building it here would put it out of reach.
 *
 * @internal
 */
final class HttpExchange
{
    public function __construct(
        private readonly McpServer $server,
        private readonly HttpClientInterface $http,
    ) {}

    /**
     * @param array<string, mixed> $message
     * @param array<string, string> $headers per-request headers, applied after McpServer::$headers
     */
    public function post(string $method, array $message, array $headers): HttpReply
    {
        $body = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new McpProtocolException(sprintf('MCP %s request could not be encoded as JSON.', $method));
        }

        $max = $this->server->maxResponseBytes;
        $exceeded = false;
        try {
            $response = $this->http->request('POST', $this->server->url, [
                'headers' => array_merge($this->server->headers, $headers, [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json, text/event-stream',
                ]),
                'body' => $body,
                'timeout' => $this->server->timeout,
                'max_duration' => $this->server->timeout,
                'max_redirects' => 0,
                'on_progress' => static function (int $downloaded, int $declared) use ($max, &$exceeded): void {
                    if ($downloaded > $max || $declared > $max) {
                        $exceeded = true;

                        throw new McpTransportException('response too large');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new McpTransportException(self::reason($method, $e, $exceeded, $max), 0, $e);
        }

        if (strlen($content) > $max) {
            throw new McpTransportException(sprintf('MCP %s response exceeded %d bytes.', $method, $max));
        }
        if ($status >= 300 && $status < 400) {
            throw new McpRedirectException($method, $status, $responseHeaders['location'][0] ?? null);
        }
        if ($status === 401 || $status === 403) {
            throw new McpAuthException($method, $status);
        }
        if (($status >= 200 && $status < 300) || $status === 400 || $status === 404) {
            return new HttpReply($method, $status, $responseHeaders, $content);
        }

        throw new McpTransportException(sprintf('MCP %s returned HTTP %d.', $method, $status));
    }

    /**
     * The message for a transport failure. It reads $e's text to classify the failure
     * but returns none of it, so the URL $e quotes stays out of the thrown message.
     *
     * $exceeded is checked first because the cap is enforced by throwing out of
     * `on_progress`, and symfony/http-client v8.1.7 re-wraps what that callback throws
     * in its own TransportException (observed against MockResponse), so the flag, not
     * $e's type, is what tells the cap apart from a network failure.
     */
    private static function reason(string $method, TransportExceptionInterface $e, bool $exceeded, int $max): string
    {
        if ($exceeded) {
            return sprintf('MCP %s response exceeded %d bytes.', $method, $max);
        }
        if ($e instanceof TimeoutExceptionInterface || stripos($e->getMessage(), 'timeout') !== false) {
            return sprintf('MCP %s timed out.', $method);
        }

        return sprintf('MCP %s failed with a transport error.', $method);
    }
}
