<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\McpServer;

test('defaults match the frozen contract', function () {
    $server = new McpServer('https://mcp.example.test/mcp');

    expect([$server->headers, $server->timeout, $server->maxResponseBytes, $server->protocolVersion, $server->maxResultBytes])
        ->toBe([[], 30.0, 1_048_576, null, 65_536]);
});

test('a protocol version other than the two spoken ones is refused at construction', function () {
    expect(fn() => new McpServer('https://x.test/', protocolVersion: '2025-06-18'))->toThrow(InvalidArgumentException::class)
        ->and((new McpServer('https://x.test/', protocolVersion: '2026-07-28'))->protocolVersion)->toBe('2026-07-28')
        ->and((new McpServer('https://x.test/', protocolVersion: '2025-11-25'))->protocolVersion)->toBe('2025-11-25');
});

test('limits must be positive', function () {
    expect(fn() => new McpServer('https://x.test/', timeout: 0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new McpServer('https://x.test/', maxResponseBytes: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new McpServer('https://x.test/', maxResultBytes: 0))->toThrow(InvalidArgumentException::class);
});

test('the session key is a sha256 that ignores header order and changes with a credential', function () {
    $a = new McpServer('https://x.test/mcp', ['Authorization' => 'Bearer one', 'X-Team' => 't']);
    $b = new McpServer('https://x.test/mcp', ['X-Team' => 't', 'Authorization' => 'Bearer one']);
    $c = new McpServer('https://x.test/mcp', ['Authorization' => 'Bearer two', 'X-Team' => 't']);

    expect($a->sessionKey())->toMatch('/^[0-9a-f]{64}$/')
        ->and($a->sessionKey())->toBe($b->sessionKey())
        ->and($a->sessionKey())->not->toBe($c->sessionKey())
        ->and($a->sessionKey())->not->toContain('Bearer');
});

test('an invalid URL is not refused here; the transport reports it', function () {
    expect((new McpServer(''))->url)->toBe('');
});
