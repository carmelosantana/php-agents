# 0001: A hand-rolled, HTTP-only MCP client with fingerprint-pinned tools

- Status: accepted (v0.16.0, 2026-09)

## Context

Hosts such as Alpaca Bot (WordPress) and Kanboard AiConnector want their model to call tools on
allowlisted remote MCP servers. The official `mcp/sdk` package pulled in about 23 packages and a
Composer plugin. Hosts run under strict egress rules (WordPress SSRF pinning), and a remote
server's tool definitions and `instructions` are untrusted input.

## Decision

- `McpClient` is written in this library with **no new Composer dependencies**. Transport is
  **Streamable HTTP only**, authenticated with static headers; no OAuth, no STDIO (a host with a
  STDIO client implements `McpClientInterface` itself).
- The HTTP client is **injected** (`Symfony\Contracts\HttpClient\HttpClientInterface`), so a host
  can hand in an SSRF-pinned client. Private-IP filtering is the host's job. Symfony class names
  never appear inside strings, so Strauss-prefixed copies keep working.
- The client sends `max_redirects: 0`, throws `McpRedirectException` on any 3xx, and also refuses
  a response a wrapper followed anyway (`redirect_count > 0`). It caps response bytes, sets
  `max_duration`, refuses CR/LF/NUL in headers, and redacts header values and session ids from
  error text.
- Protocol version is auto-detected (2026-07-28, falling back to the 2025-11-25 `initialize`
  handshake); `McpServer::$protocolVersion` pins it. Sessions live in an injected
  `McpSessionStore` keyed by a credential-free hash; on 404 the client re-initialises once and it
  never sends DELETE.
- `McpToolkit` exposes only tools in its `$allow` map whose `McpToolDefinition::fingerprint()`
  (sha256 over a canonical encoding of name, description, inputSchema, annotations) matches the
  approved pin. A changed tool is withheld and reported through `$onDrift` until re-approved.
  `guidelines()` returns `''`: server `instructions` never reach the model.
- Tool results map text first, then `structuredContent` as JSON, with placeholders for binary
  blocks, all blocks kept in `metadata['mcp']`, and a `maxResultBytes` cap (64 KiB default).

## Consequences

- The fingerprint algorithm is a cross-repo contract: Alpaca Bot stores pins computed the same
  way, and `tests/Unit/Mcp/McpToolDefinitionTest.php` pins literal digests. Changing it
  invalidates every stored approval.
- New `McpServer` / `McpToolkit` options are added only as trailing optional parameters.
- Using an MCP server takes an explicit approval step (list, show, pin). That friction is the
  security model, not an omission.
