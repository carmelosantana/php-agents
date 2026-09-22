<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

use CarmeloSantana\PHPAgents\Mcp\Internal\HttpExchange;
use CarmeloSantana\PHPAgents\Mcp\Internal\HttpReply;
use CarmeloSantana\PHPAgents\Mcp\Internal\ResultMapper;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An MCP client over Streamable HTTP that lists and calls a server's tools.
 *
 * 2025-11-25: a request made while this instance holds no session runs the `initialize`
 * handshake first, keeps the `Mcp-Session-Id` the server issues (if it issues one), and
 * sends it with `MCP-Protocol-Version` on every request after, the
 * `notifications/initialized` POST included. The session goes to the McpSessionStore, so a
 * new PHP request reuses it instead of shaking hands again — a store entry left by an
 * earlier instance, and recorded under 2025-11-25, means the first request here is the
 * caller's own, with no handshake before it. session() ignores an entry saved under any
 * other version, so the handshake runs and overwrites it.
 * A 404 to a request that carried a session means the session is gone, but only
 * when the body has no JSON-RPC error or carries -32001/-32005: the WordPress MCP Adapter
 * also answers an unknown tool with 404 (-32003). In that case the client forgets the
 * session, runs the handshake once more and retries once. It never sends DELETE; the server
 * expires the session.
 *
 * Only four JSON-RPC methods are posted from this file: `initialize` and
 * `notifications/initialized` from initialize(), and the `tools/list` and `tools/call` that
 * listTools() and callTool() hand to call(). Every one of them goes out through
 * HttpExchange::post(), which is the only HTTP call here.
 *
 * Task 13 adds protocol detection and the 2026-07-28 path to this class. Until then
 * McpServer::$protocolVersion is not read here: every request goes out as 2025-11-25 and
 * initialize() refuses any other negotiated version, so a server that speaks only
 * 2026-07-28 cannot be reached through this class yet.
 *
 * @see HttpExchange for the request it makes, its limits and its status mapping
 * @see ResultMapper for how a tools/call result becomes a ToolResult
 */
final class McpClient implements McpClientInterface
{
    public const CLIENT_VERSION = '0.16.0-dev';

    private const MAX_PAGES = 100;

    private const EXPIRED_SESSION_ERRORS = [-32001, -32005];

    private const REDACTED = '[redacted]';

    private const MAX_PATTERN_BYTES = 32_768;

    private readonly HttpExchange $exchange;

    private ?McpSession $session = null;

    /** @var list<string> every session id this instance has held, kept for redact() alone */
    private array $held = [];

    private bool $loaded = false;

    private int $nextId = 1;

    public function __construct(
        private readonly McpServer $server,
        HttpClientInterface $http,
        private readonly ?McpSessionStore $sessions = null,
    ) {
        $this->exchange = new HttpExchange($server, $http);
    }

    public function listTools(): array
    {
        $tools = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->call('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            foreach (is_array($result['tools'] ?? null) ? $result['tools'] : [] as $raw) {
                $definition = self::definition($raw);
                if ($definition !== null) {
                    $tools[] = $definition;
                }
            }
            $next = $result['nextCursor'] ?? null;
            if (!is_string($next) || $next === '') {
                return $tools;
            }
            $cursor = $next;
        }

        throw new McpProtocolException(sprintf('MCP tools/list returned more than %d pages.', self::MAX_PAGES));
    }

    public function callTool(string $name, array $arguments): ToolResult
    {
        $params = ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments];

        return ResultMapper::toToolResult($this->call('tools/call', $params), $this->server->maxResultBytes);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function call(string $method, array $params): array
    {
        if ($this->session() === null) {
            $this->initialize();
        }

        return $this->legacy($method, $params);
    }

    private function session(): ?McpSession
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $stored = $this->sessions?->load($this->server->sessionKey());
            if ($stored?->protocolVersion === McpServer::PROTOCOL_2025) {
                $this->hold($stored->sessionId);
                $this->session = $stored;
            }
        }

        return $this->session;
    }

    /**
     * One 2025-11-25 request, with at most one handshake-and-retry when the server says the
     * session is gone. $reinitialized is what stops a server that answers every request with
     * a stale-session 404 from looping.
     *
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function legacy(string $method, array $params): array
    {
        $reinitialized = false;
        while (true) {
            $sessionId = $this->session?->sessionId;
            $id = $this->nextId++;
            $reply = $this->exchange->post($method, self::request($id, $method, $params), self::sessionHeaders($sessionId));
            $message = $reply->message($id);
            if ($reply->isSuccess()) {
                return $this->result($method, $message, $reply->status);
            }
            if ($reply->status === 404 && $sessionId !== null && !$reinitialized && self::sessionExpired($message)) {
                $reinitialized = true;
                $this->forget();
                $this->initialize();
                continue;
            }

            throw $this->statusError($method, $reply, $message);
        }
    }

    /**
     * The 2025-11-25 handshake spec §2 step 3 describes: `initialize`, then the
     * `notifications/initialized` POST, then the caller's own request. Both carry
     * `MCP-Protocol-Version`; the notification carries the session id the response just
     * issued as well, when it issued one.
     *
     * That step says the notification answers 202, and spec §2's status table accepts a 202
     * from it alone. This method reads no status value of its own: the notification is
     * accepted on any 2xx (a server answering 200 is tolerated), while a 202 to `initialize`
     * is refused by result() with every other method's. A non-2xx on either becomes the same
     * error a data request would get.
     */
    private function initialize(): McpSession
    {
        $id = $this->nextId++;
        $reply = $this->exchange->post('initialize', self::request($id, 'initialize', [
            'protocolVersion' => McpServer::PROTOCOL_2025,
            'capabilities' => new \stdClass(),
            'clientInfo' => self::clientInfo(),
        ]), self::sessionHeaders(null));
        $message = $reply->message($id);
        if (!$reply->isSuccess()) {
            throw $this->statusError('initialize', $reply, $message);
        }
        $result = $this->result('initialize', $message, $reply->status);
        if (($result['protocolVersion'] ?? null) !== McpServer::PROTOCOL_2025) {
            throw new McpUnsupportedVersionException('MCP initialize negotiated a protocol version this client does not speak.');
        }

        $sessionId = $reply->header('mcp-session-id');
        $session = new McpSession(McpServer::PROTOCOL_2025, $sessionId === null || $sessionId === '' ? null : $sessionId);
        $this->hold($session->sessionId);
        $ack = $this->exchange->post(
            'notifications/initialized',
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            self::sessionHeaders($session->sessionId),
        );
        if (!$ack->isSuccess()) {
            // $this->session is null here — call() initializes only when it is, and legacy()
            // forgets before re-initializing — so the id this answer is about is $session's,
            // stored only below. hold() above is what keeps the redaction able to see it.
            throw $this->statusError('notifications/initialized', $ack, $ack->message(0));
        }
        $this->remember($session);

        return $session;
    }

    /**
     * The per-request headers of a 2025-11-25 request. $sessionId is null before the server
     * has issued one, and on every request to a server that issues none at all.
     *
     * @return array<string, string>
     */
    private static function sessionHeaders(?string $sessionId): array
    {
        $headers = ['MCP-Protocol-Version' => McpServer::PROTOCOL_2025];
        if ($sessionId !== null) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        return $headers;
    }

    private function remember(McpSession $session): void
    {
        $this->session = $session;
        $this->sessions?->save($this->server->sessionKey(), $session);
    }

    /**
     * Records a session id for the redaction, and for nothing else. An id is held from the
     * moment the server issues it or the store hands it over, and is never dropped: forget()
     * stops the client *sending* an id, while a server that echoes that same id back in a
     * later error text must still not reach an exception message (spec §2).
     */
    private function hold(?string $sessionId): void
    {
        if ($sessionId !== null && $sessionId !== '' && !in_array($sessionId, $this->held, true)) {
            $this->held[] = $sessionId;
        }
    }

    private function forget(): void
    {
        $this->session = null;
        $this->sessions?->forget($this->server->sessionKey());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function request(int $id, string $method, array $params): array
    {
        $request = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== []) {
            $request['params'] = $params;
        }

        return $request;
    }

    /** @return array{name: string, version: string} */
    private static function clientInfo(): array
    {
        return ['name' => 'php-agents', 'version' => self::CLIENT_VERSION];
    }

    /**
     * The `result` of a successful response, or the exception its `error` object deserves.
     *
     * The 202 arm is spec §2's status table: "202 Accepted, but only for the
     * `notifications/initialized` POST". It is checked before the body, so a 202 carrying a
     * complete JSON-RPC result — or a JSON-RPC error — is refused on the status alone. This
     * method is not called for the notification today (legacy() and initialize() are its only
     * callers), so the method test is what keeps the rule true rather than the call graph.
     *
     * `isset($message['error'])` is deliberate, and differs from the array_key_exists()
     * HttpReply::message() uses to recognise an envelope: a body of `{"error": null}` carries
     * the key but no error object, and JSON-RPC has no error there to report. The same
     * reading runs in statusError() and sessionExpired().
     *
     * The resultType arm is 2026-07-28's. Nothing this client sends under 2025-11-25 asks a
     * server for one and no test in this task exercises it — Task 13's do — though a
     * 2025-11-25 server that volunteers a resultType other than `complete` is refused here
     * too. It is kept so the 2026 path cannot be written without it.
     *
     * @param array<array-key, mixed>|null $message
     * @param int $status the 2xx the reply arrived with; it is McpRpcException::$httpStatus
     * @return array<array-key, mixed>
     */
    private function result(string $method, ?array $message, int $status): array
    {
        if ($status === 202 && $method !== 'notifications/initialized') {
            throw new McpProtocolException(sprintf('MCP %s returned HTTP 202, which only notifications/initialized may answer.', $method));
        }
        if ($message === null) {
            throw new McpProtocolException(sprintf('MCP %s returned no response.', $method));
        }
        if (isset($message['error'])) {
            throw $this->rpcError($method, $message, $status);
        }
        $result = $message['result'] ?? null;
        if (!is_array($result)) {
            throw new McpProtocolException(sprintf('MCP %s returned a malformed response.', $method));
        }
        if ($method !== 'tools/call' && ($result['resultType'] ?? 'complete') !== 'complete') {
            throw new McpProtocolException(sprintf('MCP %s returned an unexpected resultType.', $method));
        }

        return $result;
    }

    /**
     * The exception for a reply HttpExchange handed back rather than threw on. Each of the
     * three call sites has already found the status unsuccessful, so that is a 400 or a 404:
     * 401, 403, 3xx and every other status are exceptions before they reach here
     * (HttpExchange::post()), and a 2xx never comes this way.
     *
     * @param array<array-key, mixed>|null $message
     */
    private function statusError(string $method, HttpReply $reply, ?array $message): McpException
    {
        if (is_array($message) && isset($message['error'])) {
            return $this->rpcError($method, $message, $reply->status);
        }
        if ($reply->status === 400) {
            return new McpProtocolException(sprintf('MCP %s was rejected with HTTP 400.', $method));
        }

        return new McpTransportException(sprintf('MCP %s returned HTTP %d.', $method, $reply->status));
    }

    /**
     * The only place an McpRpcException is built, in this namespace and its Internal one:
     * HttpExchange and HttpReply hand the decoded envelope back untouched so that the
     * redaction below happens before the exception exists (spec §2, amendment 3,
     * 2026-09-21). McpRpcException formats and cuts its message inside its constructor, so
     * there is no later seam to redact in.
     *
     * @param array<array-key, mixed> $message
     */
    private function rpcError(string $method, array $message, int $status): McpRpcException
    {
        $error = is_array($message['error'] ?? null) ? $message['error'] : [];

        return new McpRpcException(
            $method,
            is_int($error['code'] ?? null) ? $error['code'] : 0,
            $this->redact(is_string($error['message'] ?? null) ? $error['message'] : ''),
            $error['data'] ?? null,
            $status,
        );
    }

    /**
     * Replaces the secrets this client holds with `[redacted]` wherever they occur literally
     * in a server's own error text. Spec §2 promises that no exception message carries a
     * header value or the session id, and McpRpcException's message is the one place server
     * text reaches a message at all: every other message in this file, in HttpExchange and in
     * HttpReply is formatted from the method name, the HTTP status and this client's own
     * configured limits (maxResponseBytes and MAX_PAGES are interpolated; nothing the server
     * sent is).
     *
     * What this covers, exactly:
     * - every non-empty value in McpServer::$headers, whatever the header is named. An empty
     *   value is skipped: as an empty alternative in the pattern it would match at every
     *   position and splice the marker between every character of the text.
     * - every session id this instance has ever held — $held, filled by hold() when the store
     *   hands one over and when `initialize` issues one, and never emptied. forget() stops the
     *   client sending an id; it does not stop a server echoing that id back afterwards, and
     *   spec §2's promise covers the message either way. This also covers the id issued by a
     *   handshake whose `notifications/initialized` POST then failed, which is held before
     *   that POST goes out and only stored after it succeeds.
     * - the longest secret first, so a secret that starts with another (a header value of
     *   `Bearer` beside a credential of `Bearer abc`) is not matched by the shorter one with
     *   its tail left published.
     *
     * It replaces in one pass, over the server's text only. An array of needles handed to
     * str_replace() is applied one after another to the output of the last, so a secret that
     * occurs inside the marker rewrites a marker already written — a header value of `red`
     * turned `[redacted]` into `[reda[redacted]ted]` in the first draft of this method.
     * The pattern carries no `u` modifier: a configured header value may be any byte string,
     * and under `u` an invalid byte in it fails the compile and preg_replace() returns null,
     * which would cost the whole message. Matching is byte-wise instead.
     *
     * Failing closed, twice. The pattern is an alternation of every configured header value
     * and every held session id, so a large enough configuration is a pattern PCRE will not
     * compile: measured here, 20 000 headers raise "regular expression is too large" at about
     * 400 KB and preg_replace() returns null. That is reachable from configuration alone, so
     * a pattern over MAX_PATTERN_BYTES is refused before the call — deterministically, and
     * without the PHP warning a failed compile raises, which PHPUnit turns into a test
     * warning and which a host could do nothing about. MAX_PATTERN_BYTES sits well below the
     * size at which that measurement failed, and well above any real configuration: HTTP
     * would not carry 32 KB of header values. A null return from preg_replace() is still checked, as a second
     * guard for whatever else PCRE may refuse; nothing in the suite reaches it. Either way the
     * server's text is dropped for the marker, never passed through unredacted.
     *
     * What it does not cover:
     * - McpRpcException::$data. That property is server-supplied and public, and no part of
     *   it reaches any message, which is what spec §2 constrains; it is passed through with
     *   its structure and types intact because Task 13 reads `data.supported` from it. A host
     *   that logs $data itself can still log something a server reflected into it.
     * - anything but a literal occurrence. A server that base64-encodes, URL-encodes, cases
     *   differently or truncates a credential before reflecting it is not caught by
     *   substring replacement.
     */
    private function redact(string $text): string
    {
        $secrets = $this->held;
        foreach ($this->server->headers as $value) {
            if ($value !== '') {
                $secrets[] = $value;
            }
        }
        if ($secrets === []) {
            return $text;
        }
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '/' . implode('|', array_map(static fn(string $s): string => preg_quote($s, '/'), $secrets)) . '/';
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return self::REDACTED;
        }
        $redacted = preg_replace($pattern, self::REDACTED, $text);

        return is_string($redacted) ? $redacted : self::REDACTED;
    }

    /** @param array<array-key, mixed>|null $message */
    private static function errorCode(?array $message): ?int
    {
        $code = is_array($message['error'] ?? null) ? ($message['error']['code'] ?? null) : null;

        return is_int($code) ? $code : null;
    }

    /** @param array<array-key, mixed>|null $message */
    private static function sessionExpired(?array $message): bool
    {
        return !isset($message['error']) || in_array(self::errorCode($message), self::EXPIRED_SESSION_ERRORS, true);
    }

    private static function definition(mixed $raw): ?McpToolDefinition
    {
        if (!is_array($raw) || !is_string($raw['name'] ?? null) || $raw['name'] === '' || !is_array($raw['inputSchema'] ?? null)) {
            return null;
        }
        $schema = $raw['inputSchema'];
        if ($schema !== [] && array_is_list($schema)) {
            return null;
        }
        $annotations = is_array($raw['annotations'] ?? null) ? $raw['annotations'] : [];
        $title = is_string($raw['title'] ?? null) ? $raw['title'] : (is_string($annotations['title'] ?? null) ? $annotations['title'] : null);

        return new McpToolDefinition(
            $raw['name'],
            is_string($raw['description'] ?? null) ? $raw['description'] : '',
            $schema,
            $annotations,
            $title,
        );
    }
}
