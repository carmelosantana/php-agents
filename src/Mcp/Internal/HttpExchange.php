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
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * One POST of one JSON-RPC message to McpServer::$url. Spec §2 confines the client to
 * that single URL, and post() hands McpServer::$url to the injected client unchanged
 * rather than deriving any other.
 *
 * The request asks for no redirects (`max_redirects: 0`), for $timeout as both the
 * idle timeout and `max_duration`, and for an `on_progress` callback that aborts past
 * $maxResponseBytes. Spec §2 ("Limits and redirects") requires the limits to hold even
 * when a host wrapper overrides those options — the HTTP client is injected precisely
 * so a host can supply its own egress — so the outcome is checked here as well. The two
 * checks are not equally strong:
 * - The cap is re-applied in full. The body is measured again after it has been read, so
 *   a wrapper that drops `on_progress` changes only when the refusal happens.
 * - The redirect refusal is not. Any 3xx that arrives here throws McpRedirectException,
 *   and followed() below throws as well when the client reports having followed one, so a
 *   wrapper that drops `max_redirects` cannot get a followed redirect returned as a reply.
 *   But the request is gone by then: nothing here keeps a credential from reaching the host
 *   a 3xx named, and a client that follows without reporting it is not caught at all. Only
 *   `max_redirects: 0`, honoured, prevents the follow itself.
 *
 * The cap is checked before the status is, so an over-cap body is a transport error at any
 * status. Once the body is within the cap, the status decides:
 * - 2xx, 400 and 404 come back as an HttpReply, because spec §2 reads protocol meaning
 *   into those bodies (version fallback, stale sessions, JSON-RPC errors).
 * - 401/403 throw McpAuthException.
 * - Every other status throws McpTransportException.
 *
 * Every exception message that leaves post() is formatted from $method, the HTTP status and
 * the configured byte cap, and from nothing else: no URL, header value, session id or
 * server-supplied text reaches one. McpRedirectException is handed the Location, but keeps
 * it on the exception rather than in the text. This matters because a transport failure's
 * own message does quote the URL — symfony/http-client v8.1.7 raises "Idle timeout reached
 * for "<url>"." — and reason() below returns none of it.
 *
 * The McpTransportException for a transport failure keeps the client's exception as its
 * previous, though, and a host that logs the chain logs that message too. Measured through
 * McpClient: for a refused port CurlHttpClient's reads "Failed to connect to 127.0.0.1 port 1
 * after 0 ms: Couldn't connect to server for "http://127.0.0.1:1/mcp"." and for an
 * unresolvable host NativeHttpClient's reads "Could not resolve host "nonexistent.invalid"."
 * — the URL or the host, and a query-string token in McpServer::$url reached it through
 * either client. For a refused port, an unresolvable host, an idle timeout and the byte cap,
 * with either client, no previous named a header. A header name or value holding CR, LF or
 * NUL is refused differently: the client raises "Invalid header: CR/LF/NUL found in "<the
 * whole header line>"." before any request, so post() refuses such a header itself, before
 * calling the client, with an McpTransportException that names the method alone and has no
 * previous (spec §2, amendment 16, 2026-09-24). It checks every header post() sends whose
 * value is a string, as McpServer::$headers' `array<string, string>` documents, the session
 * id included. An array value is outside what it covers: measured, the value
 * `["Bearer sk-live-123\n"]` passes it with PHP's "Array to string conversion" warning,
 * and the previous then reads "Invalid header: CR/LF/NUL found in "Authorization: Bearer
 * sk-live-123\n".". A Stringable object is converted by the check and refused when its
 * string holds CR, LF or NUL, and a plain object makes the check itself throw PHP's Error.
 *
 * Neither this class nor HttpReply builds an McpRpcException. That exception splices the
 * server's own error text into its message, and a server can echo a configured header
 * value or the session id back in that text, so spec §2 (amendment 3, 2026-09-21) puts the
 * redaction in McpClient, which holds both. HttpReply hands the decoded JSON-RPC envelope
 * back instead, leaving that seam open. Task 12's McpClient owns the redaction and the
 * McpRpcException; McpRpcException formats its message in its constructor, so building one
 * here would fix the unredacted text in place before McpClient could touch it.
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

        $requestHeaders = array_merge($this->server->headers, $headers, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]);
        foreach ($requestHeaders as $name => $value) {
            if (strpbrk($name . $value, "\r\n\0") !== false) {
                throw new McpTransportException(sprintf('MCP %s request has a header name or value holding CR, LF or NUL.', $method));
            }
        }

        $max = $this->server->maxResponseBytes;
        $exceeded = false;
        try {
            $response = $this->http->request('POST', $this->server->url, [
                'headers' => $requestHeaders,
                'body' => $body,
                'timeout' => $this->server->timeout,
                'max_duration' => $this->server->timeout,
                'max_redirects' => 0,
                'on_progress' => static function (int $downloaded, int $declared) use ($method, $max, &$exceeded): void {
                    if ($downloaded > $max || $declared > $max) {
                        $exceeded = true;

                        // The same text reason() returns for $exceeded. A client that lets this
                        // escape post() uncaught — one that calls on_progress outside its own
                        // try, or any transport that does not wrap it — then still raises a
                        // message of the documented shape.
                        throw new McpTransportException(sprintf('MCP %s response exceeded %d bytes.', $method, $max));
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
        if (self::followed($response)) {
            throw new McpRedirectException($method, $status, null);
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
     * Whether the client followed a redirect after all, which `max_redirects: 0` was
     * meant to stop: a wrapper that drops that option restores symfony/http-client
     * v8.1.7's default of 20, the credential goes to whatever host the 3xx named, and
     * the status seen here is the one the redirect target answered with.
     *
     * The signal is the `redirect_count` info key, which the contracts' getInfo()
     * exposes and both real clients fill in as an int — CurlResponse::getInfo() merges
     * curl_getinfo(), which always carries it, and NativeHttpClient::request() starts its
     * own at 0, which the resolver NativeHttpClient::createRedirectResolver() builds
     * increments. A client that follows without reporting it is not caught.
     * getInfo('url') is no usable second signal: after a real follow it does hold
     * the target, but on an ordinary request it holds McpServer::$url normalised —
     * `https://h` comes back `https://h/` and a space comes back `%20` — and a host
     * wrapper that pins the request to a resolved IP, the kind spec §2 invites, reports
     * a URL of its own, so comparing it would refuse requests nothing redirected.
     */
    private static function followed(ResponseInterface $response): bool
    {
        $count = $response->getInfo('redirect_count');

        return is_int($count) && $count > 0;
    }

    /**
     * The message for a transport failure. It reads $e's text to classify the failure
     * but returns none of it, so the URL $e quotes stays out of the thrown message.
     *
     * $exceeded is checked first because the cap is enforced by throwing out of
     * `on_progress`, and symfony/http-client v8.1.7 re-wraps what that callback throws
     * in its own TransportException (observed against MockResponse), so the flag, not
     * $e's type, is what tells the cap apart from a network failure.
     *
     * The timeout arm reads the text as well as the type, because only the idle `timeout`
     * raises the contracts' TimeoutExceptionInterface. McpServer::$timeout also goes out as
     * `max_duration`, and that cap is reported as a plain TransportException whose wording
     * is the client's own: curl takes it as CURLOPT_TIMEOUT_MS (CurlHttpClient::request())
     * and CurlResponse::perform() passes curl_error() through, measured here as "Operation
     * timed out after 600 milliseconds with 0 bytes received" and "Connection timed out
     * after 300 milliseconds"; the progress callback NativeHttpClient::request() installs
     * raises "Max duration was reached for ...". Not one of the three says "timeout", so all
     * three spellings are matched. Text is read for that classification only: none of it,
     * and so none of the URL it quotes, reaches the message this returns.
     */
    private static function reason(string $method, TransportExceptionInterface $e, bool $exceeded, int $max): string
    {
        if ($exceeded) {
            return sprintf('MCP %s response exceeded %d bytes.', $method, $max);
        }
        if ($e instanceof TimeoutExceptionInterface || preg_match('/timeout|timed out|max duration/i', $e->getMessage()) === 1) {
            return sprintf('MCP %s timed out.', $method);
        }

        return sprintf('MCP %s failed with a transport error.', $method);
    }
}
