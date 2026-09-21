<?php

declare(strict_types=1);

use Tests\Support\Mcp\FakeMcpServer;

// The fake is the contract every client test leans on, so its own behaviour is pinned here:
// a modern server that checks the 2026-07-28 headers, and a legacy server that answers a
// session-less request the way the WordPress MCP Adapter does.

function fakePost(FakeMcpServer $fake, array $body, array $headers = []): array
{
    $response = $fake->client()->request('POST', 'https://mcp.example.test/mcp', ['json' => $body, 'headers' => $headers]);

    return [$response->getStatusCode(), json_decode($response->getContent(false), true), $response->getHeaders(false)];
}

test('modern mode answers tools/list when the version header, method header and _meta agree', function () {
    $fake = new FakeMcpServer();
    $fake->tools = [FakeMcpServer::tool('search')];
    [$status, $body] = fakePost($fake, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]], ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']);

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
