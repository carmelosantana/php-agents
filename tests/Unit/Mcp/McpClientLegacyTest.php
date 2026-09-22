<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Mcp\McpAuthException;
use CarmeloSantana\PHPAgents\Mcp\McpClient;
use CarmeloSantana\PHPAgents\Mcp\McpProtocolException;
use CarmeloSantana\PHPAgents\Mcp\McpRpcException;
use CarmeloSantana\PHPAgents\Mcp\McpServer;
use CarmeloSantana\PHPAgents\Mcp\McpSession;
use CarmeloSantana\PHPAgents\Mcp\McpTransportException;
use CarmeloSantana\PHPAgents\Mcp\McpUnsupportedVersionException;
use Tests\Support\Mcp\ArraySessionStore;
use Tests\Support\Mcp\FakeMcpServer;

function legacyServer(array $overrides = []): McpServer
{
    return new McpServer(...array_replace([
        'url' => 'https://mcp.example.test/mcp',
        'headers' => ['Authorization' => 'Bearer t'],
        'protocolVersion' => McpServer::PROTOCOL_2025,
    ], $overrides));
}

function legacyFake(array $tools = ['search']): FakeMcpServer
{
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = array_map(static fn(string $n): array => FakeMcpServer::tool($n), $tools);

    return $fake;
}

/** A once() answer for the first request whose JSON-RPC method is $method. */
function answerFor(string $method, Closure $respond): Closure
{
    return static fn(array $r) => ($r['body']['method'] ?? null) === $method ? $respond($r['body']['id'] ?? null) : null;
}

/**
 * The request envelope spec §2 requires, asserted off every recorded request — the
 * handshake, the notification and the data requests alike. FakeMcpServer enforces none
 * of it, so without this a client that sent neither Accept nor the configured headers
 * would still pass every other test in this file.
 *
 * @param array<string, string> $configured McpServer::$headers, keyed by lower-case name
 */
function expectEnvelope(FakeMcpServer $fake, array $configured = ['authorization' => 'Bearer t']): void
{
    expect($fake->requests)->not->toBeEmpty();
    foreach ($fake->requests as $request) {
        expect($request['method'])->toBe('POST')
            ->and($request['url'])->toBe('https://mcp.example.test/mcp')
            ->and($request['headers']['accept'] ?? null)->toBe('application/json, text/event-stream')
            ->and($request['headers']['content-type'] ?? null)->toBe('application/json');
        foreach ($configured as $name => $value) {
            expect($request['headers'][$name] ?? null)->toBe($value);
        }
    }
}

test('the first request runs the handshake, then every request carries the session and version', function () {
    $fake = legacyFake(['search', 'report']);
    $tools = (new McpClient(legacyServer(), $fake->client()))->listTools();

    expect($fake->methods())->toBe(['initialize', 'notifications/initialized', 'tools/list'])
        ->and(array_map(static fn($t) => $t->name, $tools))->toBe(['search', 'report'])
        ->and($fake->requests[0]['headers'])->not->toHaveKey('mcp-session-id')
        ->and($fake->requests[0]['body']['params']['protocolVersion'])->toBe('2025-11-25')
        ->and($fake->requests[0]['body']['params']['clientInfo'])->toBe(['name' => 'php-agents', 'version' => McpClient::CLIENT_VERSION])
        ->and($fake->requests[0]['raw'])->toContain('"capabilities":{}')
        ->and($fake->requests[1]['body'])->not->toHaveKey('id')
        ->and($fake->requests[2]['headers']['mcp-session-id'])->toBe('sess-1')
        ->and($fake->requests[2]['headers']['mcp-protocol-version'])->toBe('2025-11-25');
    foreach ($fake->requests as $request) {
        expect($request['headers']['authorization'])->toBe('Bearer t')
            ->and($request['url'])->toBe('https://mcp.example.test/mcp');
    }
    expectEnvelope($fake);
});

test('every request carries Accept, Content-Type and every configured header', function () {
    $fake = legacyFake();
    $server = legacyServer(['headers' => ['Authorization' => 'Bearer t', 'X-Tenant' => 'acme']]);
    (new McpClient($server, $fake->client()))->callTool('search', ['q' => 'x']);

    expect($fake->methods())->toBe(['initialize', 'notifications/initialized', 'tools/call']);
    expectEnvelope($fake, ['authorization' => 'Bearer t', 'x-tenant' => 'acme']);
    // The notification carries the session and version headers too, not only the data requests.
    expect($fake->requests[1]['headers']['mcp-session-id'])->toBe('sess-1')
        ->and($fake->requests[1]['headers']['mcp-protocol-version'])->toBe('2025-11-25');
});

test('listTools follows nextCursor to the end', function () {
    $fake = legacyFake(['a', 'b', 'c', 'd', 'e']);
    $fake->pageSize = 2;
    $tools = (new McpClient(legacyServer(), $fake->client()))->listTools();

    expect(array_map(static fn($t) => $t->name, $tools))->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and(array_values(array_filter($fake->methods(), static fn($m) => $m === 'tools/list')))->toHaveCount(3)
        ->and($fake->requests[3]['body']['params']['cursor'])->toBe('2');
});

test('malformed tools are skipped, and description and title fall back', function () {
    $fake = legacyFake([]);
    $fake->tools = [
        ['name' => 'plain', 'inputSchema' => ['type' => 'object']],
        ['name' => 'titled', 'description' => 'D.', 'inputSchema' => ['type' => 'object'], 'annotations' => ['title' => 'From annotations', 'readOnlyHint' => true]],
        ['name' => 'top', 'title' => 'Top title', 'inputSchema' => ['type' => 'object'], 'annotations' => ['title' => 'Ignored']],
        ['description' => 'no name', 'inputSchema' => ['type' => 'object']],
        ['name' => 'no-schema'],
        ['name' => 'list-schema', 'inputSchema' => ['a', 'b']],
        'not an object',
    ];
    $tools = (new McpClient(legacyServer(), $fake->client()))->listTools();

    expect(array_map(static fn($t) => [$t->name, $t->description, $t->title], $tools))->toBe([
        ['plain', '', null],
        ['titled', 'D.', 'From annotations'],
        ['top', '', 'Top title'],
    ])->and($tools[1]->annotations)->toBe(['title' => 'From annotations', 'readOnlyHint' => true]);
});

test('the session is saved, and a later client with the same store skips the handshake', function () {
    $fake = legacyFake();
    $store = new ArraySessionStore();
    (new McpClient(legacyServer(), $fake->client(), $store))->listTools();
    (new McpClient(legacyServer(), $fake->client(), $store))->listTools();

    expect($store->sessions[legacyServer()->sessionKey()])->toEqual(new McpSession('2025-11-25', 'sess-1'))
        ->and($fake->initializeCount)->toBe(1)
        ->and($fake->methods())->toBe(['initialize', 'notifications/initialized', 'tools/list', 'tools/list']);
});

test('a stored session recorded under another protocol version is ignored, not sent', function () {
    $fake = legacyFake();
    $store = new ArraySessionStore();
    $store->sessions[legacyServer()->sessionKey()] = new McpSession(McpServer::PROTOCOL_2026, 'from-2026');
    (new McpClient(legacyServer(), $fake->client(), $store))->listTools();

    expect($fake->methods())->toBe(['initialize', 'notifications/initialized', 'tools/list'])
        ->and($store->sessions[legacyServer()->sessionKey()])->toEqual(new McpSession('2025-11-25', 'sess-1'));
    foreach ($fake->requests as $request) {
        expect($request['headers']['mcp-session-id'] ?? null)->not->toBe('from-2026');
    }
});

test('an expired session is re-initialized once and the request retried', function () {
    $fake = legacyFake();
    $store = new ArraySessionStore();
    $client = new McpClient(legacyServer(), $fake->client(), $store);
    $client->listTools();
    $fake->expireSession();
    $result = $client->callTool('search', ['q' => 'x']);

    expect($result->content)->toBe('ok')
        ->and($fake->initializeCount)->toBe(2)
        ->and(array_slice($fake->methods(), 3))->toBe(['tools/call', 'initialize', 'notifications/initialized', 'tools/call'])
        ->and($store->sessions[legacyServer()->sessionKey()]->sessionId)->toBe('sess-2');
});

test('a 404 that stays after re-initializing is thrown, not looped on', function () {
    $fake = legacyFake();
    $gone = static fn($id) => FakeMcpServer::error(404, $id, -32005, 'Session not found');
    $fake->once(answerFor('tools/call', $gone))->once(answerFor('tools/call', $gone));
    $client = new McpClient(legacyServer(), $fake->client());

    expect(fn() => $client->callTool('search', []))->toThrow(McpRpcException::class)
        ->and($fake->initializeCount)->toBe(2);
});

test('an unknown tool (404/-32003, as the WordPress Adapter answers) is an RPC error, not an expired session', function () {
    $fake = legacyFake();
    $client = new McpClient(legacyServer(), $fake->client());

    try {
        $client->callTool('nope', []);
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->rpcCode)->toBe(-32003)->and($e->httpStatus)->toBe(404);
    }
    expect($fake->initializeCount)->toBe(1);
});

test('a server that issues no session is spoken to without one', function () {
    $fake = legacyFake();
    $fake->session = null;
    $store = new ArraySessionStore();
    (new McpClient(legacyServer(), $fake->client(), $store))->listTools();

    foreach ($fake->requests as $request) {
        expect($request['headers'])->not->toHaveKey('mcp-session-id');
    }
    expect($store->sessions[legacyServer()->sessionKey()])->toEqual(new McpSession('2025-11-25'));
});

test('a negotiated version this client does not speak is refused', function () {
    $fake = legacyFake();
    $fake->once(answerFor('initialize', static fn($id) => FakeMcpServer::json(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => '2024-11-05', 'capabilities' => []]])));

    expect(fn() => (new McpClient(legacyServer(), $fake->client()))->listTools())->toThrow(McpUnsupportedVersionException::class);
});

test('an event-stream answer is read, past a server request that reuses our id', function () {
    $fake = legacyFake();
    $fake->sse = true;

    expect((new McpClient(legacyServer(), $fake->client()))->listTools()[0]->name)->toBe('search');
});

test('a JSON-RPC error on a 200 is an RPC error with its code and data', function () {
    $fake = legacyFake();
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::error(200, $id, -32603, 'boom', ['why' => 'db'])));

    try {
        (new McpClient(legacyServer(), $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect([$e->rpcCode, $e->data, $e->httpStatus])->toBe([-32603, ['why' => 'db'], 200]);
    }
});

test('a 400 with no JSON-RPC body is a protocol error', function () {
    $fake = legacyFake();
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::json(400, '')));

    expect(fn() => (new McpClient(legacyServer(), $fake->client()))->listTools())->toThrow(McpProtocolException::class);
});

test('a refused credential on the handshake is an auth error', function () {
    $fake = legacyFake();
    $fake->once(answerFor('initialize', static fn($id) => FakeMcpServer::json(401, '')));

    expect(fn() => (new McpClient(legacyServer(), $fake->client()))->listTools())->toThrow(McpAuthException::class);
});

test('callTool sends empty arguments as an object and maps the result', function () {
    $fake = legacyFake();
    $fake->results['search'] = static fn(array $a) => ['content' => [['type' => 'text', 'text' => 'found 2']]];
    $fake->results['fail'] = static fn(array $a) => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'no index']]];
    $client = new McpClient(legacyServer(), $fake->client());
    $found = $client->callTool('search', []);
    $failed = $client->callTool('fail', ['q' => 'x']);

    expect($found->content)->toBe('found 2')
        ->and($fake->requests[2]['raw'])->toContain('"arguments":{}')
        ->and($failed->status)->toBe(ToolResultStatus::Error)
        ->and($failed->errorCode)->toBe('mcp_tool_error')
        ->and($fake->requests[3]['body']['params']['arguments'])->toBe(['q' => 'x']);
    expectEnvelope($fake);
});

test('the result cap comes from the server config', function () {
    $fake = legacyFake();
    $fake->results['search'] = static fn(array $a) => ['content' => [['type' => 'text', 'text' => str_repeat('a', 50)]]];
    $result = (new McpClient(legacyServer(['maxResultBytes' => 10]), $fake->client()))->callTool('search', []);

    expect($result->content)->toBe(str_repeat('a', 10) . "\n[truncated: 10 of 50 bytes]");
});

test('a credential and the session id a server reflects are redacted from the exception message', function () {
    $fake = legacyFake();
    $reflected = 'rejected credential Bearer t for session sess-1 on tenant acme';
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::error(200, $id, -32603, $reflected)));
    $server = legacyServer(['headers' => ['Authorization' => 'Bearer t', 'X-Tenant' => 'acme']]);

    try {
        (new McpClient($server, $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('Bearer t')
            ->not->toContain('sess-1')
            ->not->toContain('acme')
            ->and($e->getMessage())->toContain('MCP tools/list failed with JSON-RPC error -32603')
            ->and($e->getMessage())->toContain('rejected credential [redacted] for session [redacted] on tenant [redacted]');
    }
});

test('redaction skips an empty header value rather than replacing between every character', function () {
    $fake = legacyFake();
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::error(200, $id, -32603, 'plain text')));
    $server = legacyServer(['headers' => ['Authorization' => 'Bearer t', 'X-Empty' => '']]);

    try {
        (new McpClient($server, $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->toEndWith(': plain text');
    }
});

test('a credential reflected past the 200-byte cut is redacted before the cut, not after', function () {
    $fake = legacyFake();
    $reflected = str_repeat('x', 190) . ' Bearer t';
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::error(200, $id, -32603, $reflected)));

    try {
        (new McpClient(legacyServer(), $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('Bearer t');
    }
});

test('a secret that is a prefix of another does not leave the rest of it published', function () {
    $fake = legacyFake();
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::error(200, $id, -32603, 'sent Bearer abc')));
    // Configured shortest first on purpose: matching 'Bearer' where 'Bearer abc' also starts
    // would redact the prefix and publish the credential's tail as "[redacted] abc".
    $server = legacyServer(['headers' => ['X-Part' => 'Bearer', 'Authorization' => 'Bearer abc']]);

    try {
        (new McpClient($server, $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('abc')->and($e->getMessage())->toEndWith(': sent [redacted]');
    }
});

test('a secret that occurs inside the marker does not corrupt what was already redacted', function () {
    $fake = legacyFake();
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::error(200, $id, -32603, 'red Bearer t')));
    // 'red' is a substring of "[redacted]". A replacement pass that re-reads its own output
    // rewrites the marker it just wrote, which is how "[reda[redacted]ted]" happens.
    $server = legacyServer(['headers' => ['Authorization' => 'Bearer t', 'X-Odd' => 'red']]);

    try {
        (new McpClient($server, $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->toEndWith(': [redacted] [redacted]');
    }
});

test('a header value that is not valid UTF-8 redacts byte-wise instead of wiping the message', function () {
    $fake = legacyFake();
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::error(200, $id, -32603, 'plain Bearer t text')));
    // A host may configure a credential of arbitrary bytes. Server text always arrives valid
    // (json_decode refuses anything else), so the only invalid UTF-8 redact() can meet is a
    // secret — which must not make the match fail and take the whole message with it.
    $server = legacyServer(['headers' => ['Authorization' => 'Bearer t', 'X-Binary' => "\xC3\x28"]]);

    try {
        (new McpClient($server, $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->toEndWith(': plain [redacted] text');
    }
});

test('the session id the handshake just issued is redacted from a refused notifications/initialized', function () {
    $fake = legacyFake();
    $fake->once(answerFor('notifications/initialized', static fn($id) => FakeMcpServer::error(400, $id, -32600, 'session sess-1 was rejected')));

    try {
        (new McpClient(legacyServer(), $fake->client()))->listTools();
        $this->fail('expected an RPC error');
    } catch (McpRpcException $e) {
        expect($e->getMessage())->not->toContain('sess-1')
            ->and($e->getMessage())->toBe('MCP notifications/initialized failed with JSON-RPC error -32600: session [redacted] was rejected');
    }
});

test('an error key whose value is null is not a JSON-RPC error', function () {
    $fake = legacyFake();
    $fake->once(answerFor('tools/list', static fn($id) => FakeMcpServer::json(200, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => null,
        'result' => ['tools' => [FakeMcpServer::tool('search')]],
    ])));

    expect(array_map(static fn($t) => $t->name, (new McpClient(legacyServer(), $fake->client()))->listTools()))->toBe(['search']);
});

test('a 404 whose error key is null is read as an expired session, then as a transport error', function () {
    $fake = legacyFake();
    $empty = static fn($id) => FakeMcpServer::json(404, ['jsonrpc' => '2.0', 'id' => $id, 'error' => null]);
    $fake->once(answerFor('tools/call', $empty))->once(answerFor('tools/call', $empty));
    $client = new McpClient(legacyServer(), $fake->client());

    expect(fn() => $client->callTool('search', []))->toThrow(McpTransportException::class)
        ->and($fake->initializeCount)->toBe(2);
});
