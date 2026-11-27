<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Server\Authenticator;
use CarmeloSantana\PHPAgents\Mcp\Server\HttpAdapter;
use CarmeloSantana\PHPAgents\Mcp\Server\Server;
use CarmeloSantana\PHPAgents\Mcp\Server\Tool;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolError;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolRegistry;

function requestEventAuthenticator(bool $accepted = true): Authenticator
{
    return new class ($accepted) implements Authenticator {
        public function __construct(private bool $accepted) {}

        public function authenticate(string $user, string $token): ?array
        {
            return $this->accepted ? ['username' => 'agent.php', 'private' => $token] : null;
        }
    };
}

// Mutation: add args to the request event.
it('emits one argument-free event for a tool call', function (): void {
    $events = [];
    $registry = new ToolRegistry();
    $registry->add(new Tool('task', 'Task actions', ['type' => 'object'], fn () => true, profiles: ['full']));
    $adapter = new HttpAdapter(
        new Server($registry),
        requestEventAuthenticator(),
        onRequest: function (array $event) use (&$events): void {
            $events[] = $event;
        },
    );

    [$status] = $adapter->handle(
        'POST',
        ['Authorization' => 'Bearer submitted-user:header-secret'],
        '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"task","arguments":{"note":"secret-sentinel-123"}}}',
    );

    expect($status)->toBe(200)
        ->and($events)->toHaveCount(1);

    $encoded = json_encode($events[0], JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('secret-sentinel-123')
        ->and($encoded)->not->toContain('header-secret')
        ->and(array_keys($events[0]))->toBe(['user', 'tool', 'outcome', 'ms', 'ip'])
        ->and($events[0]['ip'])->toBeNull()
        ->and($events[0]['user'])->toBe('agent.php')
        ->and($events[0]['tool'])->toBe('task')
        ->and($events[0]['outcome'])->toBe('ok')
        ->and($events[0]['ms'])->toBeFloat()->toBeGreaterThanOrEqual(0.0);
});

// Mutation: skip the request event on a 401.
it('emits one unauthenticated event for a rejected credential', function (): void {
    $events = [];
    $adapter = new HttpAdapter(
        new Server(new ToolRegistry()),
        requestEventAuthenticator(false),
        onRequest: function (array $event) use (&$events): void {
            $events[] = $event;
        },
    );

    [$status] = $adapter->handle(
        'POST',
        ['Authorization' => 'Bearer submitted-user:header-secret'],
        '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"task"}}',
    );

    expect($status)->toBe(401)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['user'])->toBeNull()
        ->and($events[0]['tool'])->toBeNull()
        ->and($events[0]['outcome'])->toBe('unauthenticated');
});

it('reports tool call failures by outcome', function (array $params, string $outcome): void {
    $events = [];
    $registry = new ToolRegistry();
    $registry->add(new Tool('task', 'Task actions', ['type' => 'object'], function (): never {
        throw new ToolError('private failure');
    }, profiles: ['full']));
    $registry->add(new Tool('restricted', 'Restricted tool', ['type' => 'object'], fn () => true, profiles: ['admin']));
    $adapter = new HttpAdapter(
        new Server($registry),
        requestEventAuthenticator(),
        onRequest: function (array $event) use (&$events): void {
            $events[] = $event;
        },
    );

    [$status] = $adapter->handle(
        'POST',
        ['Authorization' => 'Bearer agent.php:header-secret'],
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params], JSON_THROW_ON_ERROR),
    );

    expect($status)->toBe(200)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['tool'])->toBe($params['name'])
        ->and($events[0]['outcome'])->toBe($outcome);
})->with([
    // Mutation: treat a handler error as a successful call.
    'handler error' => [['name' => 'task'], 'tool_error'],
    // Mutation: treat a JSON-RPC error as a successful call.
    'invalid arguments' => [['name' => 'task', 'arguments' => [1]], 'invalid'],
    // Mutation: classify a profile refusal as a handler error.
    'tool outside profile' => [['name' => 'restricted'], 'denied'],
]);

it('caps the event tool name at 128 bytes while preserving UTF-8', function (string $name, string $expected): void {
    $events = [];
    $adapter = new HttpAdapter(
        new Server(new ToolRegistry()),
        requestEventAuthenticator(),
        onRequest: function (array $event) use (&$events): void {
            $events[] = $event;
        },
    );

    [$status, , $body] = $adapter->handle(
        'POST',
        ['Authorization' => 'Bearer agent.php:header-secret'],
        json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name]], JSON_THROW_ON_ERROR),
    );

    expect($status)->toBe(200)
        ->and(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['result']['content'][0]['text'])->toBe('unknown tool')
        ->and($events)->toHaveCount(1)
        ->and($events[0]['outcome'])->toBe('denied')
        ->and($events[0]['tool'])->toBe($expected);
})->with([
    // Mutation: emit the entire client-supplied tool name.
    'long ASCII name' => [str_repeat('a', 2_000), str_repeat('a', 128)],
    // Mutation: truncate inside a multibyte character.
    'split UTF-8 character' => [str_repeat('a', 127).'é', str_repeat('a', 127)],
]);
