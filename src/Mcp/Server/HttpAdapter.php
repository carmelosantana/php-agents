<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

final readonly class HttpAdapter
{
    private const CHALLENGE = 'Bearer realm="kanboard-mcp", error="invalid_token"';

    /**
     * @param (\Closure(array{user: string|int|null, tool: ?string, outcome: 'ok'|'tool_error'|'invalid'|'denied'|'unauthenticated', ms: float, ip?: string}): void)|null $onRequest
     * @param ?string $ip Client address supplied by the host for the request.
     */
    public function __construct(
        private Server $server,
        private Authenticator $authenticator,
        private string $profile = 'full',
        private int $maxBodyBytes = 1_048_576,
        private ?\Closure $onRequest = null,
        private ?string $ip = null,
    ) {}

    /**
     * @param array<string, string> $headers HTTP headers keyed by name.
     * @return array{int, array<string, string>, string} Status, headers and body.
     */
    public function handle(string $method, array $headers, string $body): array
    {
        $started = hrtime(true);
        $plainText = ['Content-Type' => 'text/plain; charset=utf-8'];

        if ($method !== 'POST') {
            return $this->respond($started, 'invalid', 405, $plainText + ['Allow' => 'POST'], 'POST required.');
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        $user = $this->authenticate($headers['authorization'] ?? '');
        if ($user === null) {
            return $this->respond(
                $started,
                'unauthenticated',
                401,
                $plainText + ['WWW-Authenticate' => self::CHALLENGE],
                'Invalid credentials.',
            );
        }

        if (strlen($body) > $this->maxBodyBytes) {
            return $this->respond($started, 'invalid', 413, $plainText, 'Request body too large.', $user);
        }

        $reply = $this->server->dispatchJson($body, new CallContext($user, $this->profile));
        $tool = $this->onRequest === null ? null : self::requestedTool($body);
        if ($reply === null) {
            return $this->respond($started, 'ok', 202, [], '', $user, $tool);
        }

        return $this->respond(
            $started,
            self::outcome($reply),
            200,
            ['Content-Type' => 'application/json'],
            json_encode($reply, JSON_THROW_ON_ERROR),
            $user,
            $tool,
        );
    }

    /**
     * @param array<string, mixed> $reply
     * @return 'ok'|'tool_error'|'invalid'|'denied'
     */
    private static function outcome(array $reply): string
    {
        if (isset($reply['error'])) {
            return 'invalid';
        }

        $result = $reply['result'] ?? null;
        if (!is_array($result) || ($result['isError'] ?? false) !== true) {
            return 'ok';
        }

        // The server uses the same result for missing tools and tools outside the active profile.
        return ($result['content'][0]['text'] ?? null) === 'unknown tool' ? 'denied' : 'tool_error';
    }

    /** @return array<string, mixed>|null */
    private function authenticate(string $header): ?array
    {
        if (preg_match('/\A(Bearer|Basic)[ \t]+([^\x00-\x20\x7f]+)\z/i', trim($header, " \t"), $matches) !== 1) {
            return null;
        }

        $credentials = strcasecmp($matches[1], 'Basic') === 0
            ? base64_decode($matches[2], true)
            : $matches[2];
        if ($credentials === false || preg_match('/[\x00-\x1f\x7f]/', $credentials) !== 0) {
            return null;
        }

        [$user, $token] = explode(':', $credentials, 2) + [1 => ''];
        if ($user === '' || strcasecmp($user, 'jsonrpc') === 0 || trim($token) === '') {
            return null;
        }

        return $this->authenticator->authenticate($user, $token);
    }

    private static function requestedTool(string $body): ?string
    {
        $message = json_decode($body, true);
        if (!is_array($message) || ($message['method'] ?? null) !== 'tools/call') {
            return null;
        }

        $name = $message['params']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param 'ok'|'tool_error'|'invalid'|'denied'|'unauthenticated' $outcome
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $user
     * @return array{int, array<string, string>, string}
     */
    private function respond(
        int|float $started,
        string $outcome,
        int $status,
        array $headers,
        string $body,
        ?array $user = null,
        ?string $tool = null,
    ): array {
        if ($this->onRequest !== null) {
            $identity = $user['username'] ?? $user['id'] ?? null;
            $event = [
                'user' => is_string($identity) || is_int($identity) ? $identity : null,
                'tool' => $tool,
                'outcome' => $outcome,
                'ms' => (hrtime(true) - $started) / 1_000_000.0,
            ];
            if ($this->ip !== null) {
                $event['ip'] = $this->ip;
            }
            ($this->onRequest)($event);
        }

        return [$status, ['Cache-Control' => 'no-store'] + $headers, $body];
    }
}
