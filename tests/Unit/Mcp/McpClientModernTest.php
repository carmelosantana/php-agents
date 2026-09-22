<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\McpClient;
use CarmeloSantana\PHPAgents\Mcp\McpProtocolException;
use CarmeloSantana\PHPAgents\Mcp\McpRpcException;
use CarmeloSantana\PHPAgents\Mcp\McpServer;
use CarmeloSantana\PHPAgents\Mcp\McpSession;
use CarmeloSantana\PHPAgents\Mcp\McpUnsupportedVersionException;
use Tests\Support\Mcp\ArraySessionStore;
use Tests\Support\Mcp\FakeMcpServer;

function autoServer(array $overrides = []): McpServer
{
    return new McpServer(...array_replace(['url' => 'https://mcp.example.test/mcp', 'headers' => ['Authorization' => 'Bearer t']], $overrides));
}

function modernFake(array $tools = ['search']): FakeMcpServer
{
    $fake = new FakeMcpServer();
    $fake->tools = array_map(static fn(string $n): array => FakeMcpServer::tool($n), $tools);

    return $fake;
}

function versionError(array $supported): Closure
{
    return static fn(array $r) => ($r['body']['method'] ?? null) === 'tools/list'
        ? FakeMcpServer::error(400, $r['body']['id'], -32022, 'Unsupported protocol version', ['supported' => $supported, 'requested' => '2026-07-28'])
        : null;
}

/**
 * Spec §2's "Every request" bullets, plus the two 2026-07-28 request headers, asserted off
 * every recorded request rather than off the fake's verdict.
 *
 * FakeMcpServer's MODERN mode checks `MCP-Protocol-Version` and `Mcp-Method`, so those two
 * are not unique to this helper. Accept, Content-Type, the URL, the HTTP method and the
 * configured header are: MODERN reads none of them. Nor does it read `Mcp-Session-Id` —
 * only its LEGACY gate does — so spec §2's "statelessly" is pinned here and in the first
 * test alone.
 */
function expectModernEnvelope(FakeMcpServer $fake): void
{
    expect($fake->requests)->not->toBeEmpty();
    foreach ($fake->requests as $request) {
        expect($request['method'])->toBe('POST')
            ->and($request['url'])->toBe('https://mcp.example.test/mcp')
            ->and($request['headers']['accept'] ?? null)->toBe('application/json, text/event-stream')
            ->and($request['headers']['content-type'] ?? null)->toBe('application/json')
            ->and($request['headers']['authorization'] ?? null)->toBe('Bearer t')
            ->and($request['headers']['mcp-protocol-version'] ?? null)->toBe('2026-07-28')
            ->and($request['headers']['mcp-method'] ?? null)->toBe($request['body']['method'])
            ->and($request['headers'])->not->toHaveKey('mcp-session-id');
    }
}

test('a 2026-07-28 server is spoken to statelessly from the first request', function () {
    $fake = modernFake();
    $store = new ArraySessionStore();
    $tools = (new McpClient(autoServer(), $fake->client(), $store))->listTools();
    $first = $fake->requests[0];

    expect($tools[0]->name)->toBe('search')
        ->and($fake->methods())->toBe(['tools/list'])
        ->and($first['headers']['mcp-protocol-version'])->toBe('2026-07-28')
        ->and($first['headers']['mcp-method'])->toBe('tools/list')
        ->and($first['headers'])->not->toHaveKey('mcp-session-id')
        ->and($first['body']['params']['_meta']['io.modelcontextprotocol/protocolVersion'])->toBe('2026-07-28')
        ->and($first['body']['params']['_meta']['io.modelcontextprotocol/clientInfo'])->toBe(['name' => 'php-agents', 'version' => McpClient::CLIENT_VERSION])
        ->and($first['raw'])->toContain('"io.modelcontextprotocol/clientCapabilities":{}')
        ->and($store->sessions[autoServer()->sessionKey()])->toEqual(new McpSession('2026-07-28'));
});

test('a 2025-11-25 server is detected by its 400, then remembered', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = [FakeMcpServer::tool('search')];
    $store = new ArraySessionStore();
    (new McpClient(autoServer(), $fake->client(), $store))->listTools();
    (new McpClient(autoServer(), $fake->client(), $store))->listTools();

    expect($fake->methods())->toBe(['tools/list', 'initialize', 'notifications/initialized', 'tools/list', 'tools/list'])
        ->and($fake->requests[4]['headers']['mcp-protocol-version'])->toBe('2025-11-25')
        ->and($fake->requests[4]['headers']['mcp-session-id'])->toBe('sess-1')
        ->and($store->sessions[autoServer()->sessionKey()])->toEqual(new McpSession('2025-11-25', 'sess-1'));
});

test('-32022 that still lists 2026-07-28 is retried once', function () {
    $fake = modernFake();
    $fake->once(versionError(['2026-07-28']));

    expect((new McpClient(autoServer(), $fake->client()))->listTools())->toHaveCount(1)
        ->and($fake->methods())->toBe(['tools/list', 'tools/list']);
});

test('-32022 that lists only 2025-11-25 falls back to the handshake', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = [FakeMcpServer::tool('search')];
    $fake->once(versionError(['2025-11-25']));

    expect((new McpClient(autoServer(), $fake->client()))->listTools())->toHaveCount(1)
        ->and($fake->initializeCount)->toBe(1);
});

test('-32022 with no shared version is refused', function () {
    $fake = modernFake();
    $fake->once(versionError(['2024-11-05']));

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->listTools())->toThrow(McpUnsupportedVersionException::class);
});

test('a modern header error is an RPC error, never a fallback', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->once(static fn(array $r) => FakeMcpServer::error(400, $r['body']['id'] ?? null, -32020, 'Header mismatch'));

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->listTools())->toThrow(McpRpcException::class)
        ->and($fake->initializeCount)->toBe(0);
});

test('a pinned 2026-07-28 never falls back, and is never written to the store', function () {
    // McpRpcException, not the McpProtocolException the plan's listing asked for: the
    // fallback 400 carries the Adapter's -32600, and the parent class cannot tell "the pin
    // refused the fallback" from any other protocol error. Probed, the class is the
    // subclass. The empty store is the other half: session() builds a pinned 2026-07-28
    // session in memory and nothing detected anything, so remember() is never reached.
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $store = new ArraySessionStore();
    $server = autoServer(['protocolVersion' => '2026-07-28']);

    expect(fn() => (new McpClient($server, $fake->client(), $store))->listTools())->toThrow(McpRpcException::class)
        ->and($fake->initializeCount)->toBe(0)
        ->and($store->sessions)->toBe([]);
});

test('a remembered 2026-07-28 that the server no longer accepts is forgotten and detection runs again', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = [FakeMcpServer::tool('search')];
    $store = new ArraySessionStore();
    $store->sessions[autoServer()->sessionKey()] = new McpSession('2026-07-28');
    (new McpClient(autoServer(), $fake->client(), $store))->listTools();

    expect($fake->methods())->toBe(['tools/list', 'tools/list', 'initialize', 'notifications/initialized', 'tools/list'])
        ->and($store->sessions[autoServer()->sessionKey()]->protocolVersion)->toBe('2025-11-25');
});

test('a stored session in a version this client does not know is ignored', function () {
    $fake = modernFake();
    $store = new ArraySessionStore();
    $store->sessions[autoServer()->sessionKey()] = new McpSession('1999-01-01', 'x');
    (new McpClient(autoServer(), $fake->client(), $store))->listTools();

    expect($fake->requests[0]['headers']['mcp-protocol-version'])->toBe('2026-07-28');
});

test('an unknown resultType on tools/list is a protocol error', function () {
    $fake = modernFake();
    $fake->once(static fn(array $r) => FakeMcpServer::json(200, ['jsonrpc' => '2.0', 'id' => $r['body']['id'], 'result' => ['resultType' => 'streaming', 'tools' => []]]));

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->listTools())->toThrow(McpProtocolException::class);
});

test('input_required with only requestState is retried with the state echoed and a new id', function () {
    $fake = modernFake();
    $fake->once(static fn(array $r) => ($r['body']['method'] ?? null) === 'tools/call'
        ? FakeMcpServer::json(200, ['jsonrpc' => '2.0', 'id' => $r['body']['id'], 'result' => ['resultType' => 'input_required', 'requestState' => 'st-1']])
        : null);
    $client = new McpClient(autoServer(), $fake->client());
    $client->listTools();
    $result = $client->callTool('search', ['q' => 'x']);
    [$first, $second] = [$fake->requests[1]['body'], $fake->requests[2]['body']];

    expect($result->content)->toBe('ok')
        ->and($second['params']['requestState'])->toBe('st-1')
        ->and($second['params']['arguments'])->toBe(['q' => 'x'])
        ->and($second['id'])->not->toBe($first['id']);
});

test('input_required that asks for client input is a protocol error', function () {
    $fake = modernFake();
    $fake->results['search'] = static fn(array $a) => ['resultType' => 'input_required', 'inputRequests' => ['ask' => ['method' => 'elicitation/create']]];

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->callTool('search', []))->toThrow(McpProtocolException::class);
});

test('input_required is retried at most three times', function () {
    $fake = modernFake(['loop']);
    $fake->results['loop'] = static fn(array $a) => ['resultType' => 'input_required', 'requestState' => 'again'];

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->callTool('loop', []))->toThrow(McpProtocolException::class)
        ->and(array_values(array_filter($fake->methods(), static fn($m) => $m === 'tools/call')))->toHaveCount(4);
});

test('calling before listing lists first, so x-mcp-header values can be sent', function () {
    $fake = modernFake();
    (new McpClient(autoServer(), $fake->client()))->callTool('search', []);

    expect($fake->methods())->toBe(['tools/list', 'tools/call'])
        ->and($fake->requests[1]['headers']['mcp-name'])->toBe('search');
});

test('a tool name that is not plain ASCII is sent base64-encoded in Mcp-Name', function () {
    $fake = modernFake(['café']);
    (new McpClient(autoServer(), $fake->client()))->callTool('café', []);

    expect($fake->requests[1]['headers']['mcp-name'])->toBe('=?base64?' . base64_encode('café') . '?=');
});

test('x-mcp-header arguments become Mcp-Param headers, and an invalid annotation drops the tool', function () {
    $fake = modernFake([]);
    $fake->tools = [
        FakeMcpServer::tool('regional', ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]),
        FakeMcpServer::tool('broken', ['type' => 'object', 'properties' => ['n' => ['type' => 'number', 'x-mcp-header' => 'N']]]),
    ];
    $client = new McpClient(autoServer(), $fake->client());
    $names = array_map(static fn($t) => $t->name, $client->listTools());
    $client->callTool('regional', ['region' => 'eu-west']);

    expect($names)->toBe(['regional'])
        ->and($fake->requests[1]['headers']['mcp-param-region'])->toBe('eu-west');
});

test('a 2025-11-25 server keeps a tool whose x-mcp-header would be invalid, and sends no Mcp-Param headers', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = [FakeMcpServer::tool('broken', ['type' => 'object', 'properties' => ['n' => ['type' => 'number', 'x-mcp-header' => 'N']]])];
    $client = new McpClient(autoServer(['protocolVersion' => '2025-11-25']), $fake->client());

    expect($client->listTools())->toHaveCount(1);
    $client->callTool('broken', ['n' => 1]);
    expect(array_keys(end($fake->requests)['headers']))->not->toContain('mcp-param-n');
});

test('every 2026-07-28 request carries the whole envelope, on both methods', function () {
    $fake = modernFake();
    (new McpClient(autoServer(), $fake->client()))->callTool('search', []);

    expect($fake->methods())->toBe(['tools/list', 'tools/call']);
    expectModernEnvelope($fake);
});

test('a paged 2026-07-28 listing keeps the x-mcp-header map of every page', function () {
    // The map is built into a local and assigned once, so a page-two reset would lose page
    // one's entry and send no Mcp-Param-Region below.
    $fake = modernFake([]);
    $fake->tools = [
        FakeMcpServer::tool('regional', ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]),
        FakeMcpServer::tool('plain'),
    ];
    $fake->pageSize = 1;
    $client = new McpClient(autoServer(), $fake->client());
    $names = array_map(static fn($t) => $t->name, $client->listTools());
    $client->callTool('regional', ['region' => 'eu-west']);

    expect($names)->toBe(['regional', 'plain'])
        ->and($fake->methods())->toBe(['tools/list', 'tools/list', 'tools/call'])
        ->and($fake->requests[1]['body']['params']['cursor'])->toBe('1')
        ->and($fake->requests[2]['headers']['mcp-param-region'])->toBe('eu-west');
    expectModernEnvelope($fake);
});

test('a tools/call result with no resultType at all is complete', function () {
    // FakeMcpServer stamps `complete` on every MODERN result, so spec §2 step 4's "absent
    // means complete" branch cannot come from the fake; this scripts the whole response.
    $fake = modernFake();
    $fake->once(static fn(array $r) => ($r['body']['method'] ?? null) === 'tools/call'
        ? FakeMcpServer::json(200, ['jsonrpc' => '2.0', 'id' => $r['body']['id'], 'result' => ['content' => [['type' => 'text', 'text' => 'bare']]]])
        : null);

    expect((new McpClient(autoServer(), $fake->client()))->callTool('search', [])->content)->toBe('bare');
});

test('a 2026-07-28 answer arriving as text/event-stream is read the same way', function () {
    // The WordPress MCP Adapter never sends SSE (trunk 4ff9806 answers a GET with 405 and
    // has no text/event-stream anywhere), so FakeMcpServer is the only exercise this gets.
    $fake = modernFake();
    $fake->sse = true;

    expect((new McpClient(autoServer(), $fake->client()))->callTool('search', [])->content)->toBe('ok');
    expectModernEnvelope($fake);
});

test('a credential a 2026-07-28 server reflects in a 200 is redacted', function () {
    // modern() hands a successful reply to result(), which is where an `error` in a 2xx
    // becomes an McpRpcException. An instance call, not a static one: the redaction needs
    // McpServer::$headers.
    $fake = modernFake();
    $fake->once(static fn(array $r) => ($r['body']['method'] ?? null) === 'tools/call'
        ? FakeMcpServer::error(200, $r['body']['id'], -32603, 'token Bearer sk-modern refused')
        : null);
    $client = new McpClient(autoServer(['headers' => ['Authorization' => 'Bearer sk-modern']]), $fake->client());

    try {
        $client->callTool('search', []);
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('sk-modern')
            ->and($e->getMessage())->toEndWith(': token [redacted] refused');
    }
});

test('a credential a 2026-07-28 server reflects in a 404 is redacted', function () {
    // The other seam modern() adds: a status that is neither 2xx nor 400 goes to
    // statusError() from inside the modern loop.
    $fake = modernFake();
    $fake->once(static fn(array $r) => FakeMcpServer::error(404, $r['body']['id'], -32601, 'no route for Bearer sk-modern'));
    $client = new McpClient(autoServer(['headers' => ['Authorization' => 'Bearer sk-modern']]), $fake->client());

    try {
        $client->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('sk-modern')
            ->and($e->getMessage())->toEndWith(': no route for [redacted]');
    }
});

test('a credential in the 400 a pinned 2026-07-28 refuses to fall back on is redacted', function () {
    // The last of the four seams: call() turns the fallback signal into an error when a pin
    // forbids the fallback, and re-reads the envelope with message(0) to do it.
    $fake = modernFake();
    $fake->once(static fn(array $r) => FakeMcpServer::error(400, $r['body']['id'], -32600, 'Bearer sk-modern is not welcome here'));
    $server = autoServer(['headers' => ['Authorization' => 'Bearer sk-modern'], 'protocolVersion' => '2026-07-28']);

    try {
        (new McpClient($server, $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('sk-modern')
            ->and($e->getMessage())->toEndWith(': [redacted] is not welcome here')
            ->and($fake->initializeCount)->toBe(0);
    }
});

test('a JSON-RPC error a 2026-07-28 server answers 200 with is an error, not a result', function () {
    // FakeMcpServer's MODERN unknown-tool branch: HTTP 200 carrying -32602.
    $fake = modernFake();

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->callTool('ghost', []))
        ->toThrow(McpRpcException::class, 'MCP tools/call failed with JSON-RPC error -32602: Unknown tool: ghost');
});

test('a 202 to a 2026-07-28 tools/list is refused, complete result and all', function () {
    // modern() hands result() the reply's own status, the way legacy() does. With 200
    // hard-coded there instead, this body would be accepted as a listing.
    $fake = modernFake();
    $fake->once(static fn(array $r) => FakeMcpServer::json(202, ['jsonrpc' => '2.0', 'id' => $r['body']['id'], 'result' => ['resultType' => 'complete', 'tools' => []]]));

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->listTools())
        ->toThrow(McpProtocolException::class, 'MCP tools/list returned HTTP 202, which only notifications/initialized may answer.');
});

test('a credential in the 400 of a modern header error is redacted', function () {
    // The fourth seam: a 400 whose code is in MODERN_RPC_ERRORS goes to statusError() from
    // inside modern()'s loop, one branch below the 404 seam above and through the same
    // expression. A regression on this branch alone would show up nowhere else.
    $fake = modernFake();
    $fake->once(static fn(array $r) => FakeMcpServer::error(400, $r['body']['id'], -32020, 'header Bearer sk-modern mismatch'));
    $client = new McpClient(autoServer(['headers' => ['Authorization' => 'Bearer sk-modern']]), $fake->client());

    try {
        $client->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('sk-modern')
            ->and($e->getMessage())->toEndWith(': header [redacted] mismatch');
    }
});

test('an inputRequests key is refused even when what it holds is empty', function () {
    // Spec §2 step 4 refuses `inputRequests`, on the key. A truthiness test instead lets
    // `inputRequests: []` through into the requestState loop, where it ends as "did not
    // complete" after MAX_INPUT_ROUNDS + 1 calls rather than being refused on sight.
    $fake = modernFake();
    $fake->results['search'] = static fn(array $a) => ['resultType' => 'input_required', 'inputRequests' => [], 'requestState' => 'again'];

    expect(fn() => (new McpClient(autoServer(), $fake->client()))->callTool('search', []))
        ->toThrow(McpProtocolException::class, 'MCP tools/call asked for client input, which this client does not provide.')
        ->and(array_values(array_filter($fake->methods(), static fn($m) => $m === 'tools/call')))->toHaveCount(1);
});
