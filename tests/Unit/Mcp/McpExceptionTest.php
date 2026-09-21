<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\McpAuthException;
use CarmeloSantana\PHPAgents\Mcp\McpException;
use CarmeloSantana\PHPAgents\Mcp\McpProtocolException;
use CarmeloSantana\PHPAgents\Mcp\McpRedirectException;
use CarmeloSantana\PHPAgents\Mcp\McpRpcException;
use CarmeloSantana\PHPAgents\Mcp\McpTransportException;
use CarmeloSantana\PHPAgents\Mcp\McpUnsupportedVersionException;

test('every MCP error is a RuntimeException through McpException', function () {
    foreach ([
        new McpTransportException('x'),
        new McpRedirectException('tools/list', 302, 'https://elsewhere.test/'),
        new McpAuthException('tools/list', 401),
        new McpProtocolException('x'),
        new McpRpcException('tools/call', -32602, 'Unknown tool'),
        new McpUnsupportedVersionException('x'),
    ] as $e) {
        expect($e)->toBeInstanceOf(McpException::class)->toBeInstanceOf(RuntimeException::class);
    }
    expect(new McpRedirectException('m', 302, null))->toBeInstanceOf(McpTransportException::class)
        ->and(new McpRpcException('m', 1, 'x'))->toBeInstanceOf(McpProtocolException::class)
        ->and(new McpUnsupportedVersionException('x'))->toBeInstanceOf(McpProtocolException::class);
});

test('a redirect names the method and status but not where it pointed', function () {
    $e = new McpRedirectException('tools/list', 307, 'https://internal.example/steal?token=abc');

    expect($e->getMessage())->toBe('MCP tools/list returned HTTP 307; redirects are not followed.')
        ->and($e->status)->toBe(307)
        ->and($e->location)->toBe('https://internal.example/steal?token=abc');
});

test('auth and rpc errors carry their codes', function () {
    $auth = new McpAuthException('tools/call', 403);
    $rpc = new McpRpcException('tools/call', -32003, str_repeat('x', 500), ['tool' => 'nope'], 404);

    expect($auth->getMessage())->toBe('MCP tools/call was refused with HTTP 403; check the configured credentials.')
        ->and($auth->status)->toBe(403)
        ->and($rpc->rpcCode)->toBe(-32003)
        ->and($rpc->getCode())->toBe(-32003)
        ->and($rpc->data)->toBe(['tool' => 'nope'])
        ->and($rpc->httpStatus)->toBe(404)
        ->and(strlen($rpc->getMessage()))->toBeLessThan(400)
        ->and($rpc->getMessage())->toStartWith('MCP tools/call failed with JSON-RPC error -32003: ');
});
