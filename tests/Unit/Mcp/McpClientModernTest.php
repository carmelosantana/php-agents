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

test('a pinned 2026-07-28 never falls back', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);

    expect(fn() => (new McpClient(autoServer(['protocolVersion' => '2026-07-28']), $fake->client()))->listTools())->toThrow(McpProtocolException::class)
        ->and($fake->initializeCount)->toBe(0);
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
