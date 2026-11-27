<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Server\Authenticator;
use CarmeloSantana\PHPAgents\Mcp\Server\HttpAdapter;
use CarmeloSantana\PHPAgents\Mcp\Server\Server;
use CarmeloSantana\PHPAgents\Mcp\Server\Tool;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolRegistry;

function protocolAdapter(?ToolRegistry $registry = null): HttpAdapter
{
    $auth = new class implements Authenticator {
        public function authenticate(string $user, string $token): ?array
        {
            return ['username' => $user];
        }
    };

    return new HttpAdapter(new Server($registry ?? new ToolRegistry(), 'test-mcp', '1.2.3'), $auth);
}

it('negotiates the initialize protocol version over HTTP', function (string $requested, string $expected): void {
    [$status, , $body] = protocolAdapter()->handle(
        'POST',
        ['Authorization' => 'Bearer tester:credential-sentinel'],
        json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => $requested],
        ], JSON_THROW_ON_ERROR),
    );

    expect($status)->toBe(200)
        ->and(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['result']['protocolVersion'])->toBe($expected);
})->with([
    // Mutation: always answer with the newest supported version.
    'supported older version' => ['2025-06-18', '2025-06-18'],
    // Mutation: echo an unsupported version.
    'unknown version' => ['2099-01-01', '2026-07-28'],
]);

// Mutation: require initialize before discovery, or drop a version or metadata field.
it('discovers both protocol eras on the first request', function (): void {
    [$status, , $body] = protocolAdapter()->handle(
        'POST',
        [
            'Authorization' => 'Bearer tester:credential-sentinel',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'server/discover',
        ],
        '{"jsonrpc":"2.0","id":1,"method":"server/discover"}',
    );

    expect($status)->toBe(200)
        ->and(json_decode($body, true, flags: JSON_THROW_ON_ERROR))->toEqual([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'supportedVersions' => ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'test-mcp', 'version' => '1.2.3'],
            ],
        ]);
});

it('rejects invalid protocol headers before invoking a tool', function (string $header, string $value): void {
    $calls = 0;
    $registry = new ToolRegistry();
    $registry->add(new Tool('probe', 'Count calls', ['type' => 'object'], function () use (&$calls): int {
        return ++$calls;
    }, profiles: ['full']));
    $adapter = protocolAdapter($registry);
    $headers = ['Authorization' => 'Bearer tester:credential-sentinel'];
    $request = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"probe"}}';

    [$status, , $body] = $adapter->handle('POST', $headers + [$header => $value], $request);

    expect($status)->toBe(400)
        ->and($body)->not->toContain($value, 'credential-sentinel')
        ->and($calls)->toBe(0);

    [$status, , $body] = $adapter->handle('POST', $headers + [
        'MCP-Protocol-Version' => '2025-06-18',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => 'probe',
    ], $request);

    expect($status)->toBe(200)
        ->and(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['result']['isError'])->toBeFalse()
        ->and($calls)->toBe(1);
})->with([
    // Mutation: ignore the version header.
    'unsupported version' => ['mCp-PrOtOcOl-VeRsIoN', 'unsupported-version-sentinel'],
    // Mutation: skip comparing the method header to method.
    'mismatched method' => ['mCp-MeThOd', 'mismatched-method-sentinel'],
    // Mutation: skip comparing the name header to params.name.
    'mismatched name' => ['mCp-NaMe', 'mismatched-name-sentinel'],
]);
