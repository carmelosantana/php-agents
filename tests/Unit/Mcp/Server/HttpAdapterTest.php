<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Server\Authenticator;
use CarmeloSantana\PHPAgents\Mcp\Server\CallContext;
use CarmeloSantana\PHPAgents\Mcp\Server\HttpAdapter;
use CarmeloSantana\PHPAgents\Mcp\Server\Server;
use CarmeloSantana\PHPAgents\Mcp\Server\Tool;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolRegistry;

function httpAdapterAuthenticator(?array $identity = ['id' => 10, 'username' => 'agent.php']): Authenticator
{
    return new class ($identity) implements Authenticator {
        public array $calls = [];

        public function __construct(private ?array $identity) {}

        public function authenticate(string $user, string $token): ?array
        {
            $this->calls[] = [$user, $token];

            return $this->identity;
        }
    };
}

// Mutation: accept GET or omit the Allow header.
it('accepts only POST', function (): void {
    $auth = httpAdapterAuthenticator();
    $adapter = new HttpAdapter(new Server(new ToolRegistry()), $auth);

    [$status, $headers] = $adapter->handle('GET', ['Authorization' => 'Bearer agent.php:token'], '');

    expect($status)->toBe(405)
        ->and($headers['Allow'])->toBe('POST')
        ->and($headers['Cache-Control'])->toBe('no-store')
        ->and($auth->calls)->toBe([]);
});

it('rejects missing or bad credentials without revealing them', function (?string $authorization, bool $checksToken): void {
    $auth = $checksToken ? httpAdapterAuthenticator(null) : httpAdapterAuthenticator();
    $adapter = new HttpAdapter(new Server(new ToolRegistry()), $auth);

    [$status, $headers, $body] = $adapter->handle(
        'POST',
        $authorization === null ? [] : ['Authorization' => $authorization],
        '{"jsonrpc":"2.0","id":1,"method":"ping"}',
    );

    expect($status)->toBe(401)
        ->and($headers['WWW-Authenticate'])->toBe('Bearer realm="kanboard-mcp", error="invalid_token"')
        ->and($headers['Cache-Control'])->toBe('no-store')
        ->and($body)->not->toContain('secret-sentinel')
        ->and($auth->calls)->toBe($checksToken ? [['agent.php', 'secret-sentinel']] : []);

    if ($authorization !== null) {
        expect($body)->not->toContain($authorization);
    }
})->with([
    // Mutation: permit a request with no Authorization header.
    'missing' => [null, false],
    // Mutation: accept an unsupported scheme or echo the rejected header.
    'unsupported scheme' => ['Digest agent.php:secret-sentinel', false],
    // Mutation: accept a Bearer credential with no separator.
    'missing separator' => ['Bearer secret-sentinel', false],
    // Mutation: decode Basic without strict mode and accept junk in the payload.
    'invalid base64' => ['Basic ' . base64_encode('agent.php:secret-sentinel') . '!', false],
    // Mutation: pass the app-token user to the authenticator.
    'app token bearer' => ['Bearer jsonrpc:secret-sentinel', false],
    // Mutation: refuse the app-token user only for Bearer or require exact user casing.
    'app token basic' => ['Basic ' . base64_encode('JSONRPC:secret-sentinel'), false],
    // Mutation: pass a blank token to the authenticator.
    'empty token' => ['Bearer secret-sentinel:', false],
    // Mutation: use the submitted user when the authenticator returns null.
    'refused by host' => ['Bearer agent.php:secret-sentinel', true],
]);

// Mutation: drop Basic support, treat header names or schemes as case-sensitive, or split the entire token.
it('authenticates Bearer and Basic and dispatches with the host identity and profile', function (string $authorization): void {
    $auth = httpAdapterAuthenticator();
    $registry = new ToolRegistry();
    $registry->add(new Tool('whoami', 'Current user', ['type' => 'object'], fn (array $args, CallContext $context) => [
        'id' => $context->user['id'],
        'profile' => $context->profile,
    ], profiles: ['core']));
    $adapter = new HttpAdapter(new Server($registry), $auth, profile: 'core');

    [$status, $headers, $body] = $adapter->handle(
        'POST',
        ['aUtHoRiZaTiOn' => $authorization],
        '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"whoami"}}',
    );
    $reply = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

    expect($auth->calls)->toBe([['agent.php', 'secret-sentinel:rest']])
        ->and($status)->toBe(200)
        ->and($headers['Content-Type'])->toBe('application/json')
        ->and($headers['Cache-Control'])->toBe('no-store')
        ->and($reply['result']['isError'])->toBeFalse()
        ->and(json_decode($reply['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['id' => 10, 'profile' => 'core'])
        ->and($body)->not->toContain('secret-sentinel');
})->with([
    'bearer' => ['bEaReR agent.php:secret-sentinel:rest'],
    'basic' => ['bAsIc ' . base64_encode('agent.php:secret-sentinel:rest')],
]);

// Mutation: dispatch before authenticating or reuse the preceding request's identity.
it('does not dispatch an unauthenticated request after a successful call', function (): void {
    $calls = 0;
    $registry = new ToolRegistry();
    $registry->add(new Tool('spy', 'Count calls', ['type' => 'object'], function () use (&$calls): int {
        return ++$calls;
    }, profiles: ['core']));
    $adapter = new HttpAdapter(new Server($registry), httpAdapterAuthenticator(), profile: 'core');
    $body = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"spy"}}';

    expect($adapter->handle('POST', ['Authorization' => 'Bearer agent.php:token'], $body)[0])->toBe(200)
        ->and($adapter->handle('POST', [], $body)[0])->toBe(401)
        ->and($calls)->toBe(1);
});

// Mutation: remove the byte limit, reject a body at the limit, or parse a body before checking its size.
it('checks the body size before parsing and accepts a body at the limit', function (?int $maxBodyBytes, int $limit): void {
    $server = new Server(new ToolRegistry());
    $auth = httpAdapterAuthenticator();
    $adapter = $maxBodyBytes === null
        ? new HttpAdapter($server, $auth)
        : new HttpAdapter($server, $auth, maxBodyBytes: $maxBodyBytes);
    $headers = ['Authorization' => 'Bearer agent.php:secret-sentinel'];
    $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

    [$status, $responseHeaders, $body] = $adapter->handle('POST', $headers, str_repeat('{', $limit + 1));

    expect($status)->toBe(413)
        ->and($responseHeaders['Cache-Control'])->toBe('no-store')
        ->and($body)->not->toContain('secret-sentinel')
        ->and($adapter->handle('POST', $headers, str_pad($ping, $limit))[0])->toBe(200);
})->with([
    'configured' => [64, 64],
    'default' => [null, 1_048_576],
]);

// Mutation: return JSON for a notification, drop its cache header, or alter the JSON-RPC parse error.
it('returns an empty 202 for notifications and preserves JSON-RPC parse errors', function (): void {
    $adapter = new HttpAdapter(new Server(new ToolRegistry()), httpAdapterAuthenticator());
    $headers = ['Authorization' => 'Bearer agent.php:secret-sentinel'];

    [$status, $responseHeaders, $body] = $adapter->handle(
        'POST', $headers, '{"jsonrpc":"2.0","method":"notifications/initialized"}',
    );
    expect($status)->toBe(202)
        ->and($responseHeaders['Cache-Control'])->toBe('no-store')
        ->and($body)->toBe('');

    [$status, $responseHeaders, $body] = $adapter->handle('POST', $headers, '{"secret-sentinel"');
    expect($status)->toBe(200)
        ->and($responseHeaders['Cache-Control'])->toBe('no-store')
        ->and($body)->not->toContain('secret-sentinel')
        ->and(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe(-32700);
});

// Mutation: skip the request hook or add raw request headers or body to its event.
it('calls the request hook with identity and timing metadata', function (): void {
    $events = [];
    $adapter = new HttpAdapter(
        new Server(new ToolRegistry()),
        httpAdapterAuthenticator(),
        onRequest: function (array $event) use (&$events): void {
            $events[] = $event;
        },
        ip: '192.0.2.1',
    );

    $adapter->handle(
        'POST',
        ['Authorization' => 'Bearer agent.php:header-secret', 'X-Forwarded-For' => '192.0.2.2'],
        '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"note":"body-secret"}}',
    );

    expect($events)->toHaveCount(1)
        ->and(array_keys($events[0]))->toBe(['user', 'tool', 'outcome', 'ms', 'ip'])
        ->and($events[0]['user'])->toBe('agent.php')
        ->and($events[0]['tool'])->toBeNull()
        ->and($events[0]['outcome'])->toBe('ok')
        ->and($events[0]['ms'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)
        ->and($events[0]['ip'])->toBe('192.0.2.1')
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain('header-secret', 'body-secret');
});
