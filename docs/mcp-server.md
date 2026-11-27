# Hosting the MCP server

`Mcp\Server` exposes host-defined tools over stateless HTTP. Clients using the
2026-07-28 protocol can start with `server/discover`. Older clients use
`initialize` to negotiate a version. Each HTTP request carries its own
credentials, and the server keeps no session state between requests.

The entire component lives under `src/Mcp/Server/`. It requires PHP 8.4 and
`ext-json` and has no Composer package dependencies or imports from the rest of
php-agents.

## Wire an HTTP route

Implement `Authenticator::authenticate(string $user, string $token): ?array`
using the host's credential store. Return an identity array when verification
succeeds or `null` when it fails. Keep credentials out of that array, exception
messages, and tool responses.

Register tools once in a `ToolRegistry`, then share a `Server` across routes.
Give each route an `HttpAdapter` with a profile selected by the host. A tool is
listed and callable on a route only when its `profiles` includes that route's
profile. Tool handlers receive the authenticated identity and profile through
`CallContext`. Enforce resource permissions in the host's handlers and restrict
access to privileged routes using the host's authorization rules.

The example assumes the host supplies `$verifyIdentity` as a callable and
`$method`, `$headers`, and `$body` from the HTTP request. Pass header names
and their string values, including `Authorization`, and the raw request body.

```php
use CarmeloSantana\PHPAgents\Mcp\Server\Authenticator;
use CarmeloSantana\PHPAgents\Mcp\Server\CallContext;
use CarmeloSantana\PHPAgents\Mcp\Server\HttpAdapter;
use CarmeloSantana\PHPAgents\Mcp\Server\Server;
use CarmeloSantana\PHPAgents\Mcp\Server\Tool;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolRegistry;

$authenticator = new class (\Closure::fromCallable($verifyIdentity)) implements Authenticator {
    public function __construct(private readonly \Closure $verifyIdentity) {}

    public function authenticate(string $user, string $token): ?array
    {
        return ($this->verifyIdentity)($user, $token);
    }
};

$registry = new ToolRegistry();
$registry->add(new Tool(
    name: 'whoami',
    description: 'Return the authenticated username.',
    inputSchema: ['type' => 'object'],
    handler: static fn (array $args, CallContext $ctx): array => ['username' => $ctx->user['username']],
    profiles: ['core'],
));

$server = new Server($registry, name: 'my-host', version: '1.0.0');
$adapter = new HttpAdapter($server, $authenticator, profile: 'core');
[$status, $responseHeaders, $responseBody] = $adapter->handle($method, $headers, $body);
```

The verifier in this example returns an identity with a `username` key. Send
the returned status, headers, and body through the host's HTTP response API.
The adapter accepts POST with `Authorization: Bearer <user>:<token>` or HTTP
Basic with the same username and token. It returns JSON-RPC replies as JSON
and acknowledges notifications with an empty HTTP 202 response. Use HTTPS
for endpoints that carry credentials.

## Vendor the component

Copy the complete `src/Mcp/Server/` directory from a pinned php-agents
revision into the host. Keep the namespace
`CarmeloSantana\PHPAgents\Mcp\Server\` and map it to the copied directory
in the host's autoloader. Sync the files together when upgrading and verify
the copy against the recorded checksums. The Kanboard McpServer plugin's
vendoring workflow uses `scripts/sync-mcp-server.sh` and `CHECKSUMS`.
