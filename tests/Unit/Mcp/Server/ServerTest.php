<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Server\Authenticator;
use CarmeloSantana\PHPAgents\Mcp\Server\CallContext;
use CarmeloSantana\PHPAgents\Mcp\Server\HttpAdapter;
use CarmeloSantana\PHPAgents\Mcp\Server\Server;
use CarmeloSantana\PHPAgents\Mcp\Server\Tool;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolError;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolRegistry;

function mcpDispatch(Server $server, string $method, array $params = [], string $profile = 'core'): ?array
{
    return $server->dispatch(
        ['jsonrpc' => '2.0', 'id' => 17, 'method' => $method, 'params' => $params],
        new CallContext(['id' => 10, 'username' => 'agent.php'], $profile),
    );
}

// Mutation: always negotiate the newest version.
it('negotiates initialize versions and advertises tools', function (string $asked, string $expected): void {
    $reply = mcpDispatch(new Server(new ToolRegistry()), 'initialize', [
        'protocolVersion' => $asked,
        'capabilities' => [],
        'clientInfo' => ['name' => 'test', 'version' => '0'],
    ]);

    expect($reply)->toBe([
        'jsonrpc' => '2.0',
        'id' => 17,
        'result' => [
            'protocolVersion' => $expected,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'kanboard-mcp-php', 'version' => '0.1.0'],
        ],
    ]);
})->with([
    'latest' => ['2026-07-28', '2026-07-28'],
    'nov 2025' => ['2025-11-25', '2025-11-25'],
    'jun 2025' => ['2025-06-18', '2025-06-18'],
    'mar 2025' => ['2025-03-26', '2025-03-26'],
    'unknown' => ['1999-01-01', '2026-07-28'],
]);

// Mutation: return an empty array for ping's result.
it('pings with an empty JSON object', function (): void {
    $reply = mcpDispatch(new Server(new ToolRegistry()), 'ping');

    expect(json_encode($reply, JSON_THROW_ON_ERROR))->toBe('{"jsonrpc":"2.0","id":17,"result":{}}');
});

// Mutation: collapse parse errors and invalid requests or echo an unknown method.
it('distinguishes JSON-RPC errors without echoing untrusted input', function (string $body, int $code, mixed $id): void {
    $ctx = new CallContext([], 'core');
    $reply = (new Server(new ToolRegistry()))->dispatchJson($body, $ctx);

    expect($reply['error']['code'])->toBe($code)
        ->and($reply['id'])->toBe($id)
        ->and(json_encode($reply, JSON_THROW_ON_ERROR))->not->toContain('secret-sentinel');
})->with([
    'malformed JSON' => ['{"secret-sentinel"', -32700, null],
    'null JSON' => ['null', -32600, null],
    'empty batch' => ['[]', -32600, null],
    'batch' => ['[{"jsonrpc":"2.0","method":"ping","id":1}]', -32600, null],
    'missing method' => ['{"jsonrpc":"2.0","id":1}', -32600, null],
    'bad version' => ['{"jsonrpc":"1.0","method":"ping","id":1}', -32600, null],
    'invalid id' => ['{"jsonrpc":"2.0","method":"ping","id":{"token":"secret-sentinel"}}', -32600, null],
    'unknown method' => ['{"jsonrpc":"2.0","method":"secret-sentinel","id":"request-1"}', -32601, 'request-1'],
]);

// Mutation: reply to notifications or use isset to determine whether an id is present.
it('returns no response for a notification and responds to an explicit null id', function (): void {
    $ctx = new CallContext([], 'core');
    $server = new Server(new ToolRegistry());

    expect($server->dispatchJson('{"jsonrpc":"2.0","method":"notifications/initialized"}', $ctx))->toBeNull()
        ->and($server->dispatchJson('{"jsonrpc":"2.0","method":"ping","id":null}', $ctx))->toHaveKeys(['id', 'result']);
});

// Mutation: list unfiltered tools or look up a full-only tool in the host registry.
it('lists folded tool definitions and resolves names and aliases within the profile', function (): void {
    $registry = new ToolRegistry();
    $getMe = new Tool('get_me', 'Current user', ['type' => 'object'], fn (array $args, CallContext $ctx) => [
        'user' => $ctx->user['username'],
        'profile' => $ctx->profile,
    ], profiles: ['core', 'full']);
    $task = new Tool('task', 'Task actions', [
        'type' => 'object',
        'required' => ['action'],
        'properties' => ['action' => ['enum' => ['close', 'comment', 'create', 'get', 'move', 'update']]],
    ], fn () => true, profiles: ['core', 'full']);
    $registry->add($getMe);
    $registry->add($task);
    $registry->add(new Tool('full_only', 'Full access', ['type' => 'object'], fn () => true, profiles: ['full']));
    $registry->alias('me', 'get_me');
    $registry->alias('full_alias', 'full_only');
    $server = new Server($registry);

    expect(mcpDispatch($server, 'tools/list')['result']['tools'])->toBe([$getMe->definition(), $task->definition()])
        ->and(array_column(mcpDispatch($server, 'tools/list', profile: 'full')['result']['tools'], 'name'))
        ->toBe(['get_me', 'task', 'full_only']);

    foreach (['full_only', 'full_alias', 'secret-sentinel'] as $name) {
        expect(mcpDispatch($server, 'tools/call', ['name' => $name])['result'])->toBe([
            'content' => [['type' => 'text', 'text' => 'unknown tool']],
            'isError' => true,
        ]);
    }

    $result = mcpDispatch($server, 'tools/call', ['name' => 'me'])['result'];
    expect($result['isError'])->toBeFalse()
        ->and(json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['user' => 'agent.php', 'profile' => 'core'])
        ->and($result)->not->toHaveKey('structuredContent');
});

// Mutation: invoke the handler before validating its arguments.
it('reports invalid arguments by field and skips the handler', function (array $args, string $field): void {
    $calls = 0;
    $registry = new ToolRegistry();
    $registry->add(new Tool('task', 'Task actions', [
        'type' => 'object',
        'required' => ['action', 'task_id'],
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['get', 'close']],
            'task_id' => ['type' => 'integer'],
        ],
    ], function () use (&$calls): void {
        ++$calls;
    }, profiles: ['core']));

    $reply = mcpDispatch(new Server($registry), 'tools/call', ['name' => 'task', 'arguments' => $args]);

    expect($reply['error']['code'])->toBe(-32602)
        ->and($reply['error']['message'])->toContain($field)->not->toContain('secret-sentinel')
        ->and($calls)->toBe(0);
})->with([
    'missing required field' => [['action' => 'get'], 'task_id'],
    'wrong type' => [['action' => 'get', 'task_id' => 'secret-sentinel'], 'task_id'],
    'invalid enum' => [['action' => 'secret-sentinel', 'task_id' => 5], 'action'],
]);

// Mutation: silently replace a non-object arguments value with an empty object.
it('rejects malformed tool call parameters', function (mixed $params): void {
    $server = new Server(new ToolRegistry());
    $reply = $server->dispatch(
        ['jsonrpc' => '2.0', 'method' => 'tools/call', 'id' => 1, 'params' => $params],
        new CallContext([], 'core'),
    );

    expect($reply['error']['code'])->toBe(-32602);
})->with([
    'scalar params' => ['secret-sentinel'],
    'missing name' => [[]],
    'non-string name' => [['name' => ['secret-sentinel']]],
    'list arguments' => [['name' => 'anything', 'arguments' => [1, 2]]],
    'null arguments' => [['name' => 'anything', 'arguments' => null]],
]);

// Mutation: report ToolError as a JSON-RPC error, or replace its message with a generic one.
it('returns a ToolError message as a tool error result', function (): void {
    $registry = new ToolRegistry();
    $registry->add(new Tool('task', 'Task actions', ['type' => 'object'], function (): never {
        throw new ToolError('Access denied');
    }, profiles: ['core']));

    $reply = mcpDispatch(new Server($registry), 'tools/call', ['name' => 'task']);

    expect($reply)->toBe([
        'jsonrpc' => '2.0',
        'id' => 17,
        'result' => ['content' => [['type' => 'text', 'text' => 'Access denied']], 'isError' => true],
    ]);
});

// Mutation: drop the code or data, or add structuredContent when no code was given.
it('returns a ToolError code and data as structured content', function (): void {
    $registry = new ToolRegistry();
    $registry->add(new Tool('context', 'Context', ['type' => 'object'], function (): never {
        throw new ToolError('Over budget', -32001, ['needed' => 900, 'budget' => 500]);
    }, profiles: ['core']));

    $reply = mcpDispatch(new Server($registry), 'tools/call', ['name' => 'context']);

    expect($reply['result'])->toBe([
        'content' => [['type' => 'text', 'text' => 'Over budget']],
        'isError' => true,
        'structuredContent' => [
            'code' => -32001,
            'message' => 'Over budget',
            'data' => ['needed' => 900, 'budget' => 500],
        ],
    ]);
});

// Mutation: let a ToolError's previous exception message reach the client.
it('keeps a ToolError previous exception private', function (): void {
    $registry = new ToolRegistry();
    $registry->add(new Tool('task', 'Task actions', ['type' => 'object'], function (): never {
        throw new ToolError('Procedure failed', previous: new \RuntimeException('secret-sentinel'));
    }, profiles: ['core']));

    $reply = mcpDispatch(new Server($registry), 'tools/call', ['name' => 'task']);

    expect(json_encode($reply, JSON_THROW_ON_ERROR))->not->toContain('secret-sentinel')
        ->and($reply['result']['content'][0]['text'])->toBe('Procedure failed');
});

// Mutation: catch only ToolError and JsonException, allowing other handler exceptions to escape.
it('returns and records a generic tool error when a handler throws unexpectedly', function (): void {
    $sentinel = 'secret-sentinel-throwable';
    $events = [];
    $registry = new ToolRegistry();
    $registry->add(new Tool('task', 'Task actions', ['type' => 'object'], static function () use ($sentinel): never {
        throw new \RuntimeException($sentinel);
    }, profiles: ['core']));
    $authenticator = new class implements Authenticator {
        public function authenticate(string $user, string $token): ?array
        {
            return ['username' => $user];
        }
    };
    $adapter = new HttpAdapter(
        new Server($registry),
        $authenticator,
        profile: 'core',
        onRequest: static function (array $event) use (&$events): void {
            $events[] = $event;
        },
    );

    [$status, $headers, $body] = $adapter->handle(
        'POST',
        ['Authorization' => 'Bearer agent.php:header-secret'],
        '{"jsonrpc":"2.0","id":17,"method":"tools/call","params":{"name":"task"}}',
    );

    expect($status)->toBe(200)
        ->and($headers['Cache-Control'])->toBe('no-store')
        ->and(json_decode($body, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'jsonrpc' => '2.0',
            'id' => 17,
            'result' => ['content' => [['type' => 'text', 'text' => 'tool failed']], 'isError' => true],
        ])
        ->and($body)->not->toContain($sentinel)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['outcome'])->toBe('tool_error')
        ->and(json_encode($events[0], JSON_THROW_ON_ERROR))->not->toContain($sentinel);
});

// Mutation: drop structuredContent or omit the JSON text copy.
it('returns a handler array as structured content and text when the tool declares an output schema', function (): void {
    $registry = new ToolRegistry();
    $registry->add(new Tool(
        'task',
        'Task actions',
        ['type' => 'object', 'properties' => ['task' => ['type' => 'object']]],
        fn (array $args, CallContext $ctx) => ['id' => $args['task']['id'], 'caller' => $ctx->user['id']],
        outputSchema: ['type' => 'object'],
        profiles: ['core'],
    ));
    $reply = (new Server($registry))->dispatchJson(
        '{"jsonrpc":"2.0","method":"tools/call","id":1,"params":{"name":"task","arguments":{"task":{"id":5}}}}',
        new CallContext(['id' => 10], 'core'),
    );

    expect($reply['result']['isError'])->toBeFalse()
        ->and($reply['result']['structuredContent'])->toBe(['id' => 5, 'caller' => 10])
        ->and($reply['result']['content'][0]['type'])->toBe('text')
        ->and(json_decode($reply['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe($reply['result']['structuredContent']);
});
