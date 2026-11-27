<?php

declare(strict_types=1);

it('links to the MCP server host guide from the README', function () {
    $root = dirname(__DIR__, 4);

    // Mutation: remove the host guide link from the README.
    expect(file_get_contents($root . '/README.md'))->toContain('](docs/mcp-server.md)');
});

it('runs the host guide example as an authenticated tool call', function () {
    $guidePath = dirname(__DIR__, 4) . '/docs/mcp-server.md';

    // Mutation: remove the host guide or its runnable PHP example.
    expect(is_file($guidePath))->toBeTrue();
    expect(preg_match('/^\x60\x60\x60php\R(.*?)^\x60\x60\x60$/ms', (string) file_get_contents($guidePath), $example))
        ->toBe(1);

    $handle = static function (array $headers) use ($example): array {
        $verifyIdentity = static fn (string $user, string $token): ?array => $user === 'reader' && $token === 'fixture-token'
            ? ['username' => $user]
            : null;
        $method = 'POST';
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"whoami"}}';

        eval($example[1]);

        return [$status, $responseHeaders, $responseBody];
    };

    // Mutation: register the example tool for a different profile from the route.
    [$status, $headers, $body] = $handle(['Authorization' => 'Bearer reader:fixture-token']);
    $reply = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    expect($status)->toBe(200)
        ->and($headers['Content-Type'])->toBe('application/json')
        ->and($reply['result']['isError'])->toBeFalse()
        ->and(json_decode($reply['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['username' => 'reader']);

    // Mutation: let the example authenticator accept credentials rejected by the host.
    [$status] = $handle(['Authorization' => 'Bearer reader:wrong-token']);
    expect($status)->toBe(401);
});
