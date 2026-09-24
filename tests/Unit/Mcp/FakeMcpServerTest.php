<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\McpSession;
use CarmeloSantana\PHPAgents\Mcp\McpSessionStore;
use Tests\Support\Mcp\ArraySessionStore;
use Tests\Support\Mcp\FakeMcpServer;

// The fake is the contract every client test leans on, so its own behaviour is pinned here:
// a modern server that checks the 2026-07-28 headers, and a legacy server that answers a
// session-less request the way the WordPress MCP Adapter does.

function fakePost(FakeMcpServer $fake, array $body, array $headers = []): array
{
    $response = $fake->client()->request('POST', 'https://mcp.example.test/mcp', ['json' => $body, 'headers' => $headers]);

    return [$response->getStatusCode(), json_decode($response->getContent(false), true), $response->getHeaders(false)];
}

/** The three `params._meta` fields a 2026-07-28 request carries (spec, "Protocol negotiation" step 1). */
function modernMeta(): array
{
    return [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
        'io.modelcontextprotocol/clientInfo' => ['name' => 'php-agents', 'version' => '0.0.0-test'],
    ];
}

/** @return list<list<string>> the lines of every SSE frame that carries at least one `data:` line, in order */
function sseDataFrames(string $body): array
{
    $frames = [];
    foreach (explode("\n\n", $body) as $frame) {
        $lines = explode("\n", $frame);
        if (array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'data: ')) !== []) {
            $frames[] = $lines;
        }
    }

    return $frames;
}

/** @return list<array<string, mixed>> the decoded `data:` messages of an SSE body, in order */
function sseMessages(string $body): array
{
    return array_map(static function (array $lines): array {
        $data = array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'data: '));

        return (array) json_decode(implode("\n", array_map(static fn (string $line): string => substr($line, 6), $data)), true);
    }, sseDataFrames($body));
}

/** @return list<string> the `event:` name of every data-carrying frame, '' when the frame has none */
function sseEventNames(string $body): array
{
    return array_map(static function (array $lines): string {
        $named = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'event: ')));

        return $named === [] ? '' : substr($named[0], 7);
    }, sseDataFrames($body));
}

/** @param list<array<string, mixed>> $messages "<method or result>:<id>" for each message, in order */
function sseShape(array $messages): array
{
    return array_map(static fn (array $m): string => ($m['method'] ?? 'result') . ':' . json_encode($m['id'] ?? null), $messages);
}

test('modern mode answers tools/list when the version header, method header and _meta agree', function () {
    $fake = new FakeMcpServer();
    $fake->tools = [FakeMcpServer::tool('search')];
    [$status, $body] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => modernMeta()]], ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']);

    expect($status)->toBe(200)
        ->and($body['result']['tools'][0]['name'])->toBe('search')
        ->and($body['result']['resultType'])->toBe('complete')
        ->and($fake->requests[0]['headers']['mcp-method'])->toBe('tools/list');
});

test('modern mode refuses a legacy version header with -32022', function () {
    [$status, $body] = fakePost(new FakeMcpServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['MCP-Protocol-Version' => '2025-11-25']);

    expect($status)->toBe(400)->and($body['error']['code'])->toBe(-32022);
});

test('legacy mode answers a session-less request like the WordPress MCP Adapter, then issues a session', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    [$status, $body] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['MCP-Protocol-Version' => '2026-07-28']);
    [$initStatus, $init, $headers] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']]);

    expect($status)->toBe(400)
        ->and($body['error']['code'])->toBe(-32600)
        ->and($initStatus)->toBe(200)
        ->and($init['result']['protocolVersion'])->toBe('2025-11-25')
        ->and($headers['mcp-session-id'][0])->toBe('sess-1')
        ->and($fake->initializeCount)->toBe(1);
});

test('legacy mode answers a stale session with 404 and an unknown tool with 404/-32003', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->expireSession();
    [$stale, $staleBody] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Mcp-Session-Id' => 'sess-1', 'MCP-Protocol-Version' => '2025-11-25']);
    [$unknown, $unknownBody] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nope', 'arguments' => []]], ['Mcp-Session-Id' => 'sess-2', 'MCP-Protocol-Version' => '2025-11-25']);

    expect([$stale, $staleBody['error']['code']])->toBe([404, -32005])
        ->and([$unknown, $unknownBody['error']['code']])->toBe([404, -32003]);
});

test('scripted answers win once, in order', function () {
    $fake = new FakeMcpServer();
    $fake->once(fn(array $r) => FakeMcpServer::json(503, ''));
    $first = $fake->client()->request('POST', 'https://mcp.example.test/mcp', ['json' => []]);
    $second = $fake->client()->request('POST', 'https://mcp.example.test/mcp', ['json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'x']]);

    expect($first->getStatusCode())->toBe(503)->and($second->getStatusCode())->toBe(400);
});

test('modern mode refuses _meta that is missing clientCapabilities or clientInfo with -32020', function () {
    $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'];
    $versionOnly = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'];
    $noClientInfo = $versionOnly + ['io.modelcontextprotocol/clientCapabilities' => []];
    $namelessClient = $noClientInfo + ['io.modelcontextprotocol/clientInfo' => ['version' => '0.0.0-test']];

    $answers = array_map(
        fn (array $meta): array => fakePost(new FakeMcpServer(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $meta]], $headers),
        [$versionOnly, $noClientInfo, $namelessClient],
    );

    expect(array_map(fn (array $a): array => [$a[0], $a[1]['error']['code']], $answers))
        ->toBe([[400, -32020], [400, -32020], [400, -32020]]);
});

test('legacy mode tells the missing-session -32600 apart from the unsupported-version -32600', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    [$missing, $missingBody] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['MCP-Protocol-Version' => '2026-07-28']);
    [$rejected, $rejectedBody] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['Mcp-Session-Id' => 'sess-1', 'MCP-Protocol-Version' => '2026-07-28']);
    [$accepted] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['Mcp-Session-Id' => 'sess-1', 'MCP-Protocol-Version' => '2025-06-18']);

    expect([$missing, $missingBody['error']['code'], $missingBody['error']['message']])
        ->toBe([400, -32600, 'Invalid Request: Missing Mcp-Session-Id header'])
        ->and([$rejected, $rejectedBody['error']['code'], $rejectedBody['error']['message']])
        ->toBe([400, -32600, 'Unsupported protocol version'])
        ->and($accepted)->toBe(200);
});

test('legacy mode accepts a request past the session gate that carries no version header', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = [FakeMcpServer::tool('search')];
    [$status, $body] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Mcp-Session-Id' => 'sess-1']);

    expect($status)->toBe(200)
        ->and($body['result']['tools'][0]['name'])->toBe('search')
        ->and($fake->requests[0]['headers'])->not->toHaveKey('mcp-protocol-version');
});

test('pageSize pages tools/list, with nextCursor on every page but the last', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    // Four tools in pages of two, so the last page ends exactly on the boundary: an off-by-one
    // in the nextCursor test would put a cursor on a page with nothing after it.
    $fake->tools = [FakeMcpServer::tool('a'), FakeMcpServer::tool('b'), FakeMcpServer::tool('c'), FakeMcpServer::tool('d')];
    $fake->pageSize = 2;
    $headers = ['Mcp-Session-Id' => 'sess-1', 'MCP-Protocol-Version' => '2025-11-25'];

    [, $first] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers);
    [, $last] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['cursor' => $first['result']['nextCursor']]], $headers);

    expect($first['result']['nextCursor'])->toBe('2')
        ->and($last['result'])->not->toHaveKey('nextCursor')
        ->and([...array_column($first['result']['tools'], 'name'), ...array_column($last['result']['tools'], 'name')])
        ->toBe(['a', 'b', 'c', 'd']);
});

test('sse streams a comment then event: message frames, and only legacy injects a server request', function () {
    $legacy = new FakeMcpServer(FakeMcpServer::LEGACY);
    $legacy->sse = true;
    $legacyResponse = $legacy->client()->request('POST', 'https://mcp.example.test/mcp', [
        'json' => ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list'],
        'headers' => ['Mcp-Session-Id' => 'sess-1', 'MCP-Protocol-Version' => '2025-11-25'],
    ]);
    $legacyBody = $legacyResponse->getContent(false);
    $legacyMessages = sseMessages($legacyBody);

    $modern = new FakeMcpServer();
    $modern->sse = true;
    $modernBody = $modern->client()->request('POST', 'https://mcp.example.test/mcp', [
        'json' => ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list', 'params' => ['_meta' => modernMeta()]],
        'headers' => ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'],
    ])->getContent(false);
    $modernMessages = sseMessages($modernBody);

    expect($legacyResponse->getHeaders(false)['content-type'][0])->toBe('text/event-stream')
        ->and($legacyBody)->toStartWith(": keep-alive\n\n")
        ->and(sseShape($legacyMessages))->toBe(['notifications/progress:null', 'ping:7', 'result:7'])
        ->and($legacyMessages[2]['result']['tools'])->toBe([])
        ->and(sseShape($modernMessages))->toBe(['notifications/progress:null', 'result:7'])
        ->and($modernMessages[1]['result']['resultType'])->toBe('complete')
        // The label changes no message, but it does stop a parser that assumes each frame
        // *begins* with `data: ` — the shape sseMessages() had until this change. Pin the
        // field so the fake cannot quietly stop sending it; a parser that scans a frame's
        // lines, as sseMessages() now does, passes either way.
        ->and(sseEventNames($legacyBody))->toBe(['message', 'message', 'message'])
        ->and(sseEventNames($modernBody))->toBe(['message', 'message']);
});

test('a scripted results closure sees the arguments, and its own resultType survives', function () {
    $seen = [];
    $fake = new FakeMcpServer();
    $fake->tools = [FakeMcpServer::tool('search')];
    $fake->results['search'] = function (array $arguments) use (&$seen): array {
        $seen[] = $arguments;

        return ['content' => [['type' => 'text', 'text' => 'scripted']], 'resultType' => 'input_required', 'requestState' => 'rs-1'];
    };

    [$status, $body] = fakePost(
        $fake,
        ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => ['q' => 'wp'], '_meta' => modernMeta()]],
        ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'search'],
    );

    expect($seen)->toBe([['q' => 'wp']])
        ->and($status)->toBe(200)
        ->and($body['result'])->toBe(['content' => [['type' => 'text', 'text' => 'scripted']], 'resultType' => 'input_required', 'requestState' => 'rs-1']);
});

test('ArraySessionStore round-trips a session, and forgetting one leaves nothing behind', function () {
    $store = new ArraySessionStore();
    $session = new McpSession('2025-11-25', 'sess-1');

    expect($store)->toBeInstanceOf(McpSessionStore::class)
        ->and($store->load('server-a'))->toBeNull();

    $store->save('server-a', $session);
    $store->forget('never-saved');

    expect($store->load('server-a'))->toBe($session)
        ->and($store->sessions)->toBe(['server-a' => $session]);

    $store->forget('server-a');

    expect($store->load('server-a'))->toBeNull()
        ->and($store->sessions)->toBe([]);
});
