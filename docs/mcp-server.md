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
Construct one `HttpAdapter` per request with the route's profile. The client
`ip` and `onRequest` are constructor arguments. Reusing an adapter can log the
wrong IP because it keeps the address from construction. A tool is listed and
callable on a route only when its `profiles` includes that route's
profile. Tool handlers receive the authenticated identity and profile through
`CallContext`. Enforce resource permissions in the host's handlers and restrict
access to privileged routes using the host's authorization rules.

The example assumes the host supplies `$verifyIdentity` as a callable and
`$method`, `$headers`, and `$body` from the HTTP request. Pass `$headers` as an
`array<string, string>` with lower-cased names, including `authorization`, and
pass the raw request body. PSR-7-style array values are not accepted. For a
PSR-7 request, use `getHeaderLine()` to obtain each header's string value.

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

A handler reports an expected failure by throwing `ToolError`. Its message is
sent as the text of an `isError: true` result, so only put text there that the
caller may see. `new ToolError($message, $code, $data)` also adds
`structuredContent: {code, message, data}`, which lets a host pass through
error codes such as a plugin's `-32001`. A previous exception is never sent.

Any other exception produces a generic `tool failed` result with
`isError: true`.

## Log request outcomes

Pass an optional `onRequest` closure and the client `ip` when creating the
adapter. The closure receives an event array with exactly these fields.

| Field | Value |
| --- | --- |
| `user` | The authenticated identity's `username` or `id` as a string or integer, or `null` if unavailable. |
| `tool` | The requested tool name, capped at 128 bytes, or `null` when unavailable. |
| `outcome` | `ok`, `tool_error`, `invalid`, `denied`, or `unauthenticated`. |
| `ms` | Elapsed handling time in milliseconds as a float. |
| `ip` | The client IP supplied by the host, or `null` if omitted. |

The event never includes tool arguments or request or response bodies.

## Vendor the component

Copy the complete `src/Mcp/Server/` directory from a pinned php-agents
revision into the host. Keep the namespace
`CarmeloSantana\PHPAgents\Mcp\Server\` and map it to the copied directory
in the host's autoloader. Sync the files together when upgrading and verify
the copy against the recorded checksums. The Kanboard McpServer plugin's
vendoring workflow uses `scripts/sync-mcp-server.sh` and `CHECKSUMS`.
