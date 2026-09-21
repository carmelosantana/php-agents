<?php

declare(strict_types=1);

namespace Tests\Support\Mcp;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A scripted MCP server for unit tests, handed to MockHttpClient as its response factory.
 *
 * MODERN speaks 2026-07-28. It checks `MCP-Protocol-Version` (a mismatch gets -32022), then
 * `Mcp-Method` against the body's method and the three `params._meta` fields by shape (either
 * gets -32020), and on `tools/call` also `Mcp-Name` against the tool name (-32020). An unknown
 * method gets 404/-32601, and an unknown tool gets HTTP 200 carrying -32602.
 *
 * LEGACY speaks 2025-11-25 the way the WordPress MCP Adapter (trunk 4ff9806) does:
 * - `initialize` issues `Mcp-Session-Id`, unless $session is null;
 * - while $session is not null, a request other than `initialize` that omits that header gets
 *   400/-32600, and one carrying any other id gets 404/-32005;
 * - past that session gate, a version header other than 2025-11-25 or 2025-06-18 gets
 *   400/-32600 as well, with a different message;
 * - an unknown tool gets 404/-32003.
 *
 * Every request is recorded in $requests, with headers keyed by lower-case name.
 * once() queues a one-shot answer that wins over the scripted server.
 */
final class FakeMcpServer
{
    public const MODERN = 'modern';
    public const LEGACY = 'legacy';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: mixed, raw: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> tool definitions exactly as tools/list sends them */
    public array $tools = [];

    /** @var array<string, \Closure(array<string, mixed>): array<string, mixed>> tool name => tools/call result */
    public array $results = [];

    /** Tools per tools/list page; 0 puts every tool on one page. */
    public int $pageSize = 0;

    /**
     * Answer a result with text/event-stream: a comment line, a notifications/progress
     * notification, then the response. LEGACY also injects a same-id server request before
     * the response; MODERN does not, because under 2026-07-28 "servers do not initiate
     * JSON-RPC requests" (basic/transports, Messages), which that page's Backward
     * Compatibility section calls out as a change from the earlier revisions.
     * Only reply() reads this flag; every error() answer stays application/json.
     */
    public bool $sse = false;

    /** The session id the next initialize issues and later requests must carry; null means sessionless. */
    public ?string $session = 'sess-1';

    public int $initializeCount = 0;

    private int $serial = 1;

    /** @var list<\Closure(array<string, mixed>): ?MockResponse> */
    private array $scripted = [];

    public function __construct(public string $mode = self::MODERN) {}

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this);
    }

    /** @param \Closure(array<string, mixed>): ?MockResponse $answer */
    public function once(\Closure $answer): self
    {
        $this->scripted[] = $answer;

        return $this;
    }

    /** The session the client holds is now unknown (404/-32005); the next initialize issues a new one. */
    public function expireSession(): void
    {
        $this->session = 'sess-' . ++$this->serial;
    }

    /** @return list<string> the JSON-RPC method of every recorded request */
    public function methods(): array
    {
        return array_map(static fn(array $r): string => (string) ($r['body']['method'] ?? ''), $this->requests);
    }

    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function tool(string $name, array $inputSchema = ['type' => 'object'], array $extra = []): array
    {
        return ['name' => $name, 'description' => ucfirst($name) . '.', 'inputSchema' => $inputSchema] + $extra;
    }

    /** @param list<string> $headers "Name: value" lines */
    public static function json(int $status, mixed $body, array $headers = []): MockResponse
    {
        return new MockResponse(
            is_string($body) ? $body : (string) json_encode($body),
            ['http_code' => $status, 'response_headers' => ['Content-Type: application/json', ...$headers]],
        );
    }

    public static function error(int $status, mixed $id, int $code, string $message, mixed $data = null): MockResponse
    {
        $error = ['code' => $code, 'message' => $message] + ($data === null ? [] : ['data' => $data]);

        return self::json($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $headers = [];
        foreach ($options['normalized_headers'] ?? [] as $name => $lines) {
            $headers[strtolower((string) $name)] = substr((string) $lines[0], strlen((string) $name) + 2);
        }
        $raw = is_string($options['body'] ?? null) ? $options['body'] : '';
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => json_decode($raw, true), 'raw' => $raw, 'options' => $options];
        $this->requests[] = $request;

        foreach ($this->scripted as $index => $answer) {
            $response = $answer($request);
            if ($response !== null) {
                array_splice($this->scripted, $index, 1);

                return $response;
            }
        }

        return $this->mode === self::MODERN ? $this->modern($request) : $this->legacy($request);
    }

    /** @param array<string, mixed> $request */
    private function modern(array $request): MockResponse
    {
        $body = is_array($request['body']) ? $request['body'] : [];
        $id = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');
        $version = $request['headers']['mcp-protocol-version'] ?? null;
        if ($version !== '2026-07-28') {
            return self::error(400, $id, -32022, 'Unsupported protocol version', ['supported' => ['2026-07-28'], 'requested' => $version]);
        }
        $meta = $body['params']['_meta'] ?? [];
        if (!self::metaIsWellFormed(is_array($meta) ? $meta : []) || ($request['headers']['mcp-method'] ?? null) !== $method) {
            return self::error(400, $id, -32020, 'Header mismatch');
        }

        return match ($method) {
            'server/discover' => $this->reply($id, ['resultType' => 'complete', 'supportedVersions' => ['2026-07-28'], 'capabilities' => ['tools' => new \stdClass()], 'ttlMs' => 0, 'cacheScope' => 'private']),
            'tools/list' => $this->reply($id, ['resultType' => 'complete', 'ttlMs' => 0, 'cacheScope' => 'private'] + $this->page($body['params']['cursor'] ?? null)),
            'tools/call' => self::decodeName($request['headers']['mcp-name'] ?? '') !== ($body['params']['name'] ?? null)
                ? self::error(400, $id, -32020, 'Header mismatch')
                : $this->call($id, is_array($body['params'] ?? null) ? $body['params'] : [], true),
            default => self::error(404, $id, -32601, 'Method not found'),
        };
    }

    /** @param array<string, mixed> $request */
    private function legacy(array $request): MockResponse
    {
        $body = is_array($request['body']) ? $request['body'] : [];
        $id = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');

        if ($method === 'initialize') {
            $this->initializeCount++;
            $requested = $body['params']['protocolVersion'] ?? '';
            $version = in_array($requested, ['2025-11-25', '2025-06-18'], true) ? $requested : '2025-11-25';

            return $this->reply(
                $id,
                ['protocolVersion' => $version, 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => ['name' => 'fake', 'version' => '1']],
                $this->session === null ? [] : ['Mcp-Session-Id: ' . $this->session],
            );
        }
        if ($this->session !== null) {
            $sent = $request['headers']['mcp-session-id'] ?? null;
            if ($sent === null) {
                return self::error(400, $id, -32600, 'Invalid Request: Missing Mcp-Session-Id header');
            }
            if ($sent !== $this->session) {
                return self::error(404, $id, -32005, 'Session not found');
            }
        }
        $version = $request['headers']['mcp-protocol-version'] ?? null;
        if ($version !== null && $version !== '2025-11-25' && $version !== '2025-06-18') {
            return self::error(400, $id, -32600, 'Unsupported protocol version');
        }
        if ($method === 'notifications/initialized') {
            return new MockResponse('', ['http_code' => 202]);
        }

        return match ($method) {
            'tools/list' => $this->reply($id, $this->page($body['params']['cursor'] ?? null)),
            'tools/call' => $this->call($id, is_array($body['params'] ?? null) ? $body['params'] : [], false),
            default => self::error(404, $id, -32601, 'Method not found'),
        };
    }

    /** @return array<string, mixed> */
    private function page(mixed $cursor): array
    {
        $offset = is_string($cursor) ? (int) $cursor : 0;
        if ($this->pageSize === 0) {
            return ['tools' => $this->tools];
        }
        $page = ['tools' => array_slice($this->tools, $offset, $this->pageSize)];
        if ($offset + $this->pageSize < count($this->tools)) {
            $page['nextCursor'] = (string) ($offset + $this->pageSize);
        }

        return $page;
    }

    /** @param array<string, mixed> $params */
    private function call(mixed $id, array $params, bool $modern): MockResponse
    {
        $name = (string) ($params['name'] ?? '');
        $factory = $this->results[$name] ?? null;
        if ($factory === null && !in_array($name, array_column($this->tools, 'name'), true)) {
            return $modern
                ? self::error(200, $id, -32602, 'Unknown tool: ' . $name)
                : self::error(404, $id, -32003, 'Tool not found');
        }
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $result = $factory !== null ? $factory($arguments) : ['content' => [['type' => 'text', 'text' => 'ok']]];

        // A scripted result may carry its own resultType (input_required); only a result without one is marked complete.
        return $this->reply($id, $modern ? $result + ['resultType' => 'complete'] : $result);
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $headers
     */
    private function reply(mixed $id, array $result, array $headers = []): MockResponse
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        if (!$this->sse) {
            return self::json(200, $message, $headers);
        }
        $serverRequest = $this->mode === self::LEGACY
            ? 'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'ping']) . "\n\n"
            : '';
        $body = ": keep-alive\n\n"
            . 'data: ' . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progress' => 1]]) . "\n\n"
            . $serverRequest
            . 'data: ' . json_encode($message) . "\n\n";

        return new MockResponse($body, ['http_code' => 200, 'response_headers' => ['Content-Type: text/event-stream', ...$headers]]);
    }

    /**
     * The three `params._meta` fields a 2026-07-28 request carries, checked by shape: the
     * protocol version verbatim, `clientCapabilities` as an object, and `clientInfo` with a
     * string name and a string version. Neither the name nor the version string is pinned, so
     * a client may change either without this fake noticing.
     *
     * @param array<string, mixed> $meta
     */
    private static function metaIsWellFormed(array $meta): bool
    {
        $info = $meta['io.modelcontextprotocol/clientInfo'] ?? null;

        return ($meta['io.modelcontextprotocol/protocolVersion'] ?? null) === '2026-07-28'
            && is_array($meta['io.modelcontextprotocol/clientCapabilities'] ?? null)
            && is_array($info)
            && is_string($info['name'] ?? null)
            && is_string($info['version'] ?? null);
    }

    private static function decodeName(string $value): string
    {
        if (preg_match('/^=\?base64\?(.*)\?=$/', $value, $m) === 1) {
            return (string) base64_decode($m[1], true);
        }

        return $value;
    }
}
