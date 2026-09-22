<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

use CarmeloSantana\PHPAgents\Mcp\Internal\HeaderValue;
use CarmeloSantana\PHPAgents\Mcp\Internal\HttpExchange;
use CarmeloSantana\PHPAgents\Mcp\Internal\HttpReply;
use CarmeloSantana\PHPAgents\Mcp\Internal\ParamHeaders;
use CarmeloSantana\PHPAgents\Mcp\Internal\ResultMapper;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An MCP client over Streamable HTTP that lists and calls a server's tools.
 *
 * Detecting the protocol version (the 2026-07-28 streamable-http §Backward Compatibility
 * procedure): with nothing remembered and no pin, the first request is sent as 2026-07-28,
 * with `MCP-Protocol-Version`, `Mcp-Method`/`Mcp-Name` and `params._meta` (protocol
 * version, `clientCapabilities: {}`, clientInfo). A 400 carrying -32020 or -32021 comes
 * from a 2026-07-28 server and becomes an McpRpcException; a 400 carrying -32022 is
 * retried once if the server still lists 2026-07-28, falls back if it lists only
 * 2025-11-25, and is otherwise McpUnsupportedVersionException. Any other 400 means "not a
 * 2026-07-28 server", and the client falls back to 2025-11-25. The version found is
 * remembered through the McpSessionStore. A remembered 2026-07-28 that later draws a
 * fallback 400 is forgotten, and detection runs again. McpServer::$protocolVersion pins a
 * version instead: nothing is probed, and a pinned 2026-07-28 that draws a fallback 400 is
 * an error. A 2026-07-28 pin is never written to the store; a 2025-11-25 pin still runs
 * the handshake below, which stores the session it issues.
 *
 * 2026-07-28 calls honour `resultType`:
 * - absent means complete;
 * - `input_required` carrying only `requestState` is retried with the state echoed back,
 *   at most MAX_INPUT_ROUNDS times;
 * - `inputRequests` is refused, because this client declares no capabilities;
 * - anything else is a protocol error.
 * Under 2026-07-28 a tool whose `x-mcp-header` annotations are invalid is left out of
 * listTools(), and a call sends each annotated argument as `Mcp-Param-*`
 * (Internal\ParamHeaders). Since only the listing says which arguments those are,
 * callTool() lists first when this instance hasn't listed yet and the server isn't known
 * to be 2025-11-25. Under 2025-11-25 no tool is dropped for an annotation and no
 * `Mcp-Param-*` is sent, that version having no such annotation.
 *
 * 2025-11-25: once the fallback or a pin has settled on this version, the `initialize`
 * handshake runs, and the client keeps the `Mcp-Session-Id` the server issues (if it
 * issues one) and sends it with `MCP-Protocol-Version` on every request after, the
 * `notifications/initialized` POST included. The session goes to the McpSessionStore, so a
 * new PHP request reuses it instead of shaking hands again — a store entry left by an
 * earlier instance, and recorded under 2025-11-25, means the first request here is the
 * caller's own, with no probe and no handshake before it. session() ignores an entry saved
 * under a version this client does not speak, so detection runs and overwrites it.
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
 * @see HttpExchange for the request it makes, its limits and its status mapping
 * @see ResultMapper for how a tools/call result becomes a ToolResult
 */
final class McpClient implements McpClientInterface
{
    public const CLIENT_VERSION = '0.16.0-dev';

    private const MAX_PAGES = 100;

    private const EXPIRED_SESSION_ERRORS = [-32001, -32005];

    private const MAX_INPUT_ROUNDS = 3;

    /**
     * The two 2026-07-28 error codes spec §2 step 2 answers with McpRpcException.
     * -32022 is not here: modern() handles it above this test, on its own.
     */
    private const MODERN_RPC_ERRORS = [-32020, -32021];

    private const REDACTED = '[redacted]';

    private readonly HttpExchange $exchange;

    private ?McpSession $session = null;

    /** @var list<string> every session id this instance has held, kept for redact() alone */
    private array $held = [];

    private bool $loaded = false;

    private int $nextId = 1;

    /** @var array<string, list<array{path: list<string>, header: string}>>|null tool name => x-mcp-header map, from the last listing */
    private ?array $paramHeaders = null;

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
        $paramHeaders = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->call('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            // Read after call(), which is what settles the version on the first page.
            $modern = $this->session?->protocolVersion === McpServer::PROTOCOL_2026;
            foreach (is_array($result['tools'] ?? null) ? $result['tools'] : [] as $raw) {
                $definition = self::definition($raw);
                if ($definition === null) {
                    continue;
                }
                if ($modern) {
                    $map = ParamHeaders::extract($definition->inputSchema);
                    if ($map === null) {
                        continue;
                    }
                    $paramHeaders[$definition->name] = $map;
                }
                $tools[] = $definition;
            }
            $next = $result['nextCursor'] ?? null;
            if (!is_string($next) || $next === '') {
                $this->paramHeaders = $paramHeaders;

                return $tools;
            }
            $cursor = $next;
        }

        throw new McpProtocolException(sprintf('MCP tools/list returned more than %d pages.', self::MAX_PAGES));
    }

    public function callTool(string $name, array $arguments): ToolResult
    {
        if ($this->paramHeaders === null && $this->session()?->protocolVersion !== McpServer::PROTOCOL_2025) {
            $this->listTools();
        }
        $params = ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments];
        for ($round = 0; ; $round++) {
            $result = $this->call('tools/call', $params, $name, $arguments);
            $type = $result['resultType'] ?? 'complete';
            if ($type === 'complete') {
                return ResultMapper::toToolResult($result, $this->server->maxResultBytes);
            }
            if ($type !== 'input_required') {
                throw new McpProtocolException('MCP tools/call returned an unknown resultType.');
            }
            // The key, not its truthiness: spec §2 step 4 refuses `inputRequests`, and a
            // server that sends an empty one has still asked. Probed: with !empty() here,
            // `inputRequests: []` reached the requestState loop and ended as "did not
            // complete" after four calls. The same reading HttpReply gives `error`/`method`.
            if (array_key_exists('inputRequests', $result)) {
                throw new McpProtocolException('MCP tools/call asked for client input, which this client does not provide.');
            }
            if (!is_string($result['requestState'] ?? null) || $round >= self::MAX_INPUT_ROUNDS) {
                throw new McpProtocolException('MCP tools/call did not complete.');
            }
            $params['requestState'] = $result['requestState'];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments the tool arguments, for the Mcp-Param headers
     * @return array<array-key, mixed>
     */
    private function call(string $method, array $params, ?string $toolName = null, array $arguments = []): array
    {
        $session = $this->session();
        if ($session === null) {
            return $this->probe($method, $params, $toolName, $arguments);
        }
        if ($session->protocolVersion === McpServer::PROTOCOL_2025) {
            return $this->legacy($method, $params);
        }

        $outcome = $this->modern($method, $params, $toolName, $arguments);
        if (is_array($outcome)) {
            return $outcome;
        }
        if ($this->server->protocolVersion !== null) {
            // A pin refuses the fallback: the 400 is the answer, not a signal.
            throw $this->statusError($method, $outcome, $outcome->message(0));
        }
        $this->forget();

        return $this->probe($method, $params, $toolName, $arguments);
    }

    /**
     * Spec §2 steps 1-3: one 2026-07-28 request, and the 2025-11-25 handshake behind it.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     * @return array<array-key, mixed>
     */
    private function probe(string $method, array $params, ?string $toolName, array $arguments): array
    {
        $outcome = $this->modern($method, $params, $toolName, $arguments);
        if (is_array($outcome)) {
            $this->remember(new McpSession(McpServer::PROTOCOL_2026));

            return $outcome;
        }
        $this->initialize();

        return $this->legacy($method, $params);
    }

    /**
     * One 2026-07-28 request, or two when a -32022 is worth one retry.
     *
     * `Mcp-Name` goes through HeaderValue; `Mcp-Method` does not. The `=?base64?…?=`
     * sentinel is defined for `Mcp-Name` and `Mcp-Param-*` (Internal\HeaderValue), and
     * FakeMcpServer compares `Mcp-Method` raw while decoding `Mcp-Name`; this repo's spec
     * §2 step 1 puts both under the encoding, and that line is the one this file departs
     * from, proposed as a spec amendment rather than departed from silently. No request
     * this client sends can tell the two apart: measured, HeaderValue::encode() returns
     * each of the four method names in the class docblock unchanged.
     *
     * The -32020 HeaderMismatch arm is a deliberate, documented deviation from upstream
     * too, and it is spec §2 step 2 that is implemented: upstream's 2026-07-28 text says a
     * client SHOULD re-run `tools/list` and retry once on -32020, and this client throws
     * McpRpcException without retrying. Upstream says SHOULD, not MUST, and no test in this
     * repo speaks to a real MCP server of either version — tests/Integration holds no MCP
     * test — so a retry could be validated only against a scripted double. Spec §2 step 3
     * records, from a reading of the WordPress MCP Adapter's source at trunk 4ff9806, that
     * the Adapter answers a 2026-07-28 probe with 400/-32600 and takes the fallback path;
     * that reading is not something this repo executes. McpClientModernTest's "a modern
     * header error is an RPC error, never a fallback" pins the throw.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     * @return array<array-key, mixed>|HttpReply the result, or the 400 that says the server is not 2026-07-28
     */
    private function modern(string $method, array $params, ?string $toolName, array $arguments): array|HttpReply
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => McpServer::PROTOCOL_2026,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => self::clientInfo(),
        ];
        $headers = ['MCP-Protocol-Version' => McpServer::PROTOCOL_2026, 'Mcp-Method' => $method];
        if ($toolName !== null) {
            $headers['Mcp-Name'] = HeaderValue::encode($toolName);
            $headers += ParamHeaders::headers($this->paramHeaders[$toolName] ?? [], $arguments);
        }

        $retried = false;
        while (true) {
            $id = $this->nextId++;
            $reply = $this->exchange->post($method, self::request($id, $method, $params), $headers);
            $message = $reply->message($id);
            if ($reply->isSuccess()) {
                return $this->result($method, $message, $reply->status);
            }
            if ($reply->status !== 400) {
                throw $this->statusError($method, $reply, $message);
            }
            $code = self::errorCode($message);
            if ($code === -32022) {
                $supported = self::supportedVersions($message);
                if (in_array(McpServer::PROTOCOL_2026, $supported, true) && !$retried) {
                    $retried = true;
                    continue;
                }
                if (in_array(McpServer::PROTOCOL_2025, $supported, true)) {
                    return $reply;
                }

                throw new McpUnsupportedVersionException(sprintf('MCP %s: the server supports none of the protocol versions this client speaks.', $method));
            }
            if (in_array($code, self::MODERN_RPC_ERRORS, true)) {
                throw $this->statusError($method, $reply, $message);
            }

            return $reply;
        }
    }

    /**
     * The version this instance is already speaking, or null when detection still owes an
     * answer. A stored entry is adopted only when its version is one of the two this client
     * speaks and no pin contradicts it.
     *
     * hold() records the id an adopted entry carries. This instance never ran the handshake
     * that issued that id, so this line is the only thing that records it, and a server can
     * still reflect it back in an error text (spec §2, amendment 3). McpClientLegacyTest's
     * "a session id resumed from the store is redacted after it goes stale" is the test
     * that fails when this line goes.
     */
    private function session(): ?McpSession
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $pin = $this->server->protocolVersion;
            $stored = $this->sessions?->load($this->server->sessionKey());
            $known = $stored !== null && in_array($stored->protocolVersion, [McpServer::PROTOCOL_2026, McpServer::PROTOCOL_2025], true);
            if ($known && ($pin === null || $stored->protocolVersion === $pin)) {
                $this->hold($stored->sessionId);
                $this->session = $stored;
            } elseif ($pin === McpServer::PROTOCOL_2026) {
                $this->session = new McpSession(McpServer::PROTOCOL_2026);
            } elseif ($pin === McpServer::PROTOCOL_2025) {
                $this->initialize();
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
     * from it alone. This method compares no status itself: it tests isSuccess() on both
     * replies and passes `initialize`'s status to result(), which is where a 202 to any method
     * but the notification is refused. So the notification is accepted on any 2xx (a server
     * answering 200 is tolerated) and a 202 to `initialize` is not. A non-2xx on either becomes
     * the same error a data request would get.
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
            // $this->session is null here, so the id this answer is about is $session's,
            // stored only below, and hold() above is what keeps the redaction able to see
            // it. Probed rather than argued from the call graph: a temporary throw at the
            // top of this method when $this->session !== null left the whole suite green.
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
     * method is not called for the notification today (legacy(), modern() and initialize()
     * are its only callers), so the method test is what keeps the rule true rather than the
     * call graph.
     * This class compares an HTTP status in exactly four places, and each is a spec §2 row:
     * the 404 of the stale-session rule in legacy(), the 400 modern() reads as the
     * negotiation signal, the 400 of the rejected-request rule in statusError(), and the 202
     * here.
     *
     * `isset($message['error'])` is deliberate, and differs from the array_key_exists()
     * HttpReply::message() uses to recognise an envelope: a body of `{"error": null}` carries
     * the key but no error object, and JSON-RPC has no error there to report. The same
     * reading runs in statusError() and sessionExpired().
     *
     * The resultType arm is 2026-07-28's. `tools/call` is excluded from it because callTool()
     * reads resultType itself, where `input_required` is a legal answer rather than an error.
     * Every other method is held to `complete`, under either protocol version: a 2025-11-25
     * server that volunteers another resultType is refused here too.
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
     * The exception for a reply HttpExchange handed back rather than threw on. Every call
     * site has already found the status unsuccessful, and HttpExchange::post() returns a
     * reply for a 2xx, a 400 or a 404 alone, so what arrives here is a 400 or a 404: 401,
     * 403, 3xx and every other status are exceptions before they reach here, and a 2xx never
     * comes this way.
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
     *   value is skipped. strtr() would not splice the marker between every character for it —
     *   it ignores an empty needle — but it says so with a diagnostic, "strtr(): Ignoring
     *   replacement of empty string", which PHPUnit raises as a test warning and a host would
     *   find in its log. Dropping the guard is what that costs; the text is unchanged either
     *   way. The mechanism that does splice a marker between every character is an empty
     *   *alternative* in a regular expression: measured, preg_replace('/|Bearer t/', '[R]',
     *   'abc Bearer t') gives "[R]a[R]b[R]c[R] [R][R][R]". str_replace() does not splice on an
     *   empty needle either; only the preg draft this guard was first written for could.
     * - every session id this instance has ever held — $held, filled by hold() when the store
     *   hands one over and when `initialize` issues one, and never emptied. forget() stops the
     *   client sending an id; it does not stop a server echoing that id back afterwards, and
     *   spec §2's promise covers the message either way. Both fillings are pinned by tests: an
     *   id issued by a handshake whose `notifications/initialized` POST then failed, and an id
     *   resumed from the store that the server then rejected as stale.
     *
     * strtr() with a needle map, rather than a regular expression or str_replace():
     * - it scans once and never re-reads what it has written, so a secret occurring inside the
     *   marker cannot rewrite a marker already placed. str_replace() with an array of needles
     *   does re-read, which is why it is not used here: str_replace(['Bearer abc', 'c'],
     *   '[redacted]', 'sent Bearer abc') gives "sent [reda[redacted]ted]", the `c` matching
     *   inside the marker `Bearer abc` had just been replaced with. A header value of `red`
     *   hits the same defect at another offset, turning a marker already written into
     *   "[[redacted]acted]". An early draft of this method redacted with str_replace and failed
     *   this way; it was never committed, so the probe above is the evidence, not the repo;
     * - at each position it tries the longest needle first, whatever order the map is in
     *   (measured both ways), so a value of `Bearer` beside a credential of `Bearer abc` cannot
     *   match first and leave the tail published. Nothing here sorts; that guarantee is strtr's
     *   and a test pins it;
     * - it is byte-wise, so a configured header value may be any byte string, valid UTF-8 or not;
     * - a secret of digits only becomes an *integer* array key, which strtr() still matches as
     *   its decimal string. Pinned, because the coercion is PHP's and not obvious;
     * - it has no compile step, so there is no pattern for an engine to refuse. The preg_replace()
     *   alternation this replaced did have one, and PCRE's ceiling is far lower than that draft
     *   assumed: measured on this box (PCRE2 10.42), a single literal stops compiling past
     *   32 764 bytes, and the limit moves with the number of alternations too — 1 985 values of
     *   15 bytes compile and 1 986 do not, 500 of 64 bytes compile and 501 do not. A 32 KiB byte
     *   threshold sat *above* the first ceiling and was blind to the second. Cost instead is a
     *   single scan of the text, and it is not a concern at the sizes this class works at:
     *   redact() runs once per JSON-RPC error, over the headers one server is configured with.
     *   No timing figure is quoted here, deliberately — each one that was written here turned
     *   out to be a best case that a differently shaped text falsified.
     *
     * What it does not cover:
     * - McpRpcException::$data. rpcError() passes that property through untouched, and no
     *   part of it reaches any message, which is what spec §2 constrains. A host that logs
     *   $data itself can still log something a server reflected into it.
     * - anything but a literal occurrence. A server that base64-encodes, URL-encodes, cases
     *   differently or truncates a credential before reflecting it is not caught by
     *   substring replacement.
     */
    private function redact(string $text): string
    {
        $secrets = [];
        foreach ($this->held as $sessionId) {
            $secrets[$sessionId] = self::REDACTED;
        }
        foreach ($this->server->headers as $value) {
            if ($value !== '') {
                $secrets[$value] = self::REDACTED;
            }
        }

        return $secrets === [] ? $text : strtr($text, $secrets);
    }

    /**
     * The protocol versions a -32022 lists in `error.data.supported`. Anything that is not
     * a list of strings yields none, which modern() reads as "no version in common".
     *
     * @param array<array-key, mixed>|null $message
     * @return list<string>
     */
    private static function supportedVersions(?array $message): array
    {
        $error = is_array($message['error'] ?? null) ? $message['error'] : [];
        $supported = is_array($error['data']['supported'] ?? null) ? $error['data']['supported'] : [];

        return array_values(array_filter($supported, 'is_string'));
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
