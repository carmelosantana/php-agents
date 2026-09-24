# php-agents 0.16.0: a hand-rolled MCP client and `McpToolkit`

Wayfinding map: Kanboard #4403 (project 79 "PHP Agents", 2026-09-16 to 2026-09-17). Each decision below is a comment on a closed crux ticket, and the table links it. The public contract was frozen and posted on Alpaca Bot's map, Kanboard #4364: the contract in comment 1067, addendum 1 in 1073, addendum 2 in 1078. This spec is the single written copy of those three posts.

## Why

Alpaca Bot 0.6 lets its model call tools on allowlisted remote MCP servers. Carmelo decided (Alpaca Bot Kanboard #4366, #4368) that the client lives in php-agents:
- hand-rolled, with **no new Composer packages** (`mcp/sdk` 0.8.1 was rejected: Tier 3, about 23 packages, a Composer plugin);
- **HTTP only**, authenticated with a static header, **no OAuth**;
- the **HTTP client injected**, so WordPress can hand it an SSRF-pinned egress.

Tools are allowlisted one by one and pinned to a fingerprint of their definition when approved. A tool whose definition changes is withheld until it is approved again.

php-agents has no MCP code today. Coqui has an MCP client, but it is STDIO-only (Kanboard #4412), so this is new code. Two parts of the contract were shaped so coqui can adopt it later.

The plugin builds its half in tandem against the frozen contract. Its plan Task 28 is blocked on the `v0.16.0` tag.

## Decisions

| Decision | Choice | Ticket |
| --- | --- | --- |
| Destination | Freeze and post the contract, write this spec, then a TDD plan ending in a `v0.16.0` tag and GitHub release, seeded on project 79 with Opus metadata. Implementation waits for Carmelo's go. | Kanboard #4403 |
| Public contract | The brief's shape, amended: `McpClientInterface`; the prefix and an injectable namer on `McpToolkit`; a fingerprint byte-identical to the plugin's; an `McpException` base under the typed errors. | Kanboard #4404 |
| HTTP seam | `Symfony\Contracts\HttpClient\HttpClientInterface`. Strauss already rewrites php-agents' own imports of it. Symfony class names never appear inside strings. | Kanboard #4408 |
| Protocol version | Auto-detect: try 2026-07-28, and fall back to the 2025-11-25 `initialize` handshake per the spec's backward-compatibility rule. An optional `McpServer::$protocolVersion` pins it. | Kanboard #4406 |
| Sessions | An injected `McpSessionStore` holding an `McpSession` (protocol version plus optional session id). The key is a hash of the URL and headers. On 404 the client re-initializes once. It never sends DELETE. | Kanboard #4405 |
| Raw schemas | A public `Tool\SchemaTool` and `Schema\JsonSchemaRepair`. Fixes to the OpenAI Responses strict normaliser (`strict:false` when it can't prove the schema closed) and to Gemini's recursion. A cross-provider regression corpus. | Kanboard #4407 |
| Result mapping | Text first; `structuredContent` as JSON when there is no text; placeholders for binary blocks and links; every block kept in `metadata['mcp']`; `isError` gives `mcp_tool_error`; a `maxResultBytes` cap (64 KiB) with a truncation note. | Kanboard #4409 |
| Annotations | Hint helpers on `McpToolDefinition` with the spec's defaults, plus `McpToolkit::definition($exposedName)` for host policies. The execution-policy interface is unchanged, and its stale doc is fixed. | Kanboard #4410 |
| Testing | A scripted `FakeMcpServer` behind `MockHttpClient` for every unit test. An env-gated live test that CI doesn't run. One manual run against the WordPress MCP Adapter before the tag. | Kanboard #4411 |
| Coqui | php-agents stays generic. Coqui's adoption work and four bugs found in its MCP code are filed on project 150 (Kanboard #4432–#4436). No coqui edits here. | Kanboard #4412 |

## Changes

### 1. The public contract (namespace `CarmeloSantana\PHPAgents\Mcp`)

This is exactly what was posted on Alpaca Bot Kanboard #4364. Anything added later goes at the end as an optional trailing parameter, so callers pass these by name.

```php
final readonly class McpServer
    public const PROTOCOL_2026 = '2026-07-28';
    public const PROTOCOL_2025 = '2025-11-25';
    __construct(
        string $url,
        array $headers = [],              // header name => value, sent on every request
        float $timeout = 30.0,
        int $maxResponseBytes = 1_048_576, // cap on the HTTP body
        ?string $protocolVersion = null,   // null = auto; one of the PROTOCOL_* constants pins it
        int $maxResultBytes = 65_536,      // cap on ToolResult->content
    )
    sessionKey(): string                   // the opaque, credential-free key McpSessionStore is called with

final readonly class McpToolDefinition
    __construct(string $name, string $description, array $inputSchema, array $annotations = [], ?string $title = null)
    fingerprint(): string
    readOnly(): bool; destructive(): bool; idempotent(): bool; openWorld(): bool

interface McpClientInterface
    listTools(): list<McpToolDefinition>
    callTool(string $name, array $arguments): \CarmeloSantana\PHPAgents\Tool\ToolResult

final class McpClient implements McpClientInterface
    __construct(McpServer $server, \Symfony\Contracts\HttpClient\HttpClientInterface $http, ?McpSessionStore $sessions = null)

interface McpSessionStore
    load(string $key): ?McpSession
    save(string $key, McpSession $session): void
    forget(string $key): void

final readonly class McpSession
    __construct(string $protocolVersion, ?string $sessionId = null)

final class McpToolkit implements \CarmeloSantana\PHPAgents\Contract\ToolkitInterface
    __construct(McpClientInterface $client, array $allow, string $prefix = '', ?\Closure $onDrift = null, ?\Closure $namer = null)
    definition(string $exposedName): ?McpToolDefinition

final class McpToolName
    public const MAX = 64;
    public static function fit(string $raw): string

// Errors
McpException extends \RuntimeException
├── McpTransportException          // network, timeout, byte cap, 5xx, other unexpected status
│   └── McpRedirectException       // a 3xx, or an answer the injected client reached by
│                                  // following one; carries status, and Location when there was one
├── McpAuthException               // 401, 403
└── McpProtocolException           // malformed or unexpected response
    ├── McpRpcException            // JSON-RPC error object; carries code and data
    └── McpUnsupportedVersionException
```

Two more public classes come from Kanboard #4407. They sit outside the `Mcp` namespace because they are useful to any raw-schema tool:
- `CarmeloSantana\PHPAgents\Tool\SchemaTool implements ToolInterface`: `(string $name, string $description, array $schema, \Closure $run)`.
- `CarmeloSantana\PHPAgents\Schema\JsonSchemaRepair::repair(array $schema): array`.

**Fingerprint.**
- It is `hash('sha256', $json !== false ? $json : serialize($canonical))` over `name`, `description`, `inputSchema` and `annotations`, where `$canonical` is `canonical([...])` and `$json` is `json_encode($canonical)` called with `serialize_precision` set to `-1` and set back to its prior value in a `finally` (amendment 12, 2026-09-24: this bullet used to say `hash('sha256', json_encode(canonical([...])))`, and the code cast a false result to `''`. A definition json_encode() refuses — a schema holding `1e999`, which json_decode() reads as INF — then hashed to `e3b0c442…b855` whatever its description said, so a server could rewrite an approved tool's description behind an unchanged pin; and json_encode() writes a float with `serialize_precision` digits, so a php.ini setting of 17 moved the digest of `0.1`. Found on Kanboard #4437 comment 1489; the fix is Alpaca Bot 3a2682e's, ported so the digests stay byte-identical. Probed against both McpToolDefinition and AlpacaBot\Mcp\ToolDefinition at 3a2682e: the definition `{"name":"t","description":"a","inputSchema":{"type":"object","properties":{"n":{"type":"number","maximum":1e999}}},"annotations":{"readOnlyHint":true}}` gives `6660688c022f245f5c8a9768b11ec1a3c1a0dd3de1872d301879fad7c8a34dee`, the same with description `b` gives `8176581eb88c5abdc60a54dabe456e93db0a2e0fd45179ba97db6083ad21cdbe`, and name `t`, description `a`, inputSchema `{"type":"object","properties":{"n":{"type":"number","maximum":0.1}}}`, annotations `{}` gives `da6c52e490a72e261c856c1c1c1ddd7359577aec30c49bd649189647023ebc36` at `serialize_precision` -1 and at 17. Inputs that still hash the same, each measured: `{}` and `[]`; an empty `annotations` and none; the number literals `9007199254740992.0` and `9007199254740993.0`, and `99999999999999999999` and `100000000000000000000`, which decode to one float each pair (the integer literals `9007199254740992` and `9007199254740993` hash apart); an object keyed "0" to "n" in order and the list it decodes to (out of order too, while every key is a single digit; `ksort(SORT_STRING)` puts "10" before "2", so from "10" up an out-of-order object hashes apart from the list); and an invalid UTF-8 byte and U+FFFD in the same place).
- The encoding flags are `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE`.
- `canonical()` applies `ksort(SORT_STRING)` to every array that is not a list, recursively, and never reorders a list.
- It is computed over the values `json_decode($body, true)` returns.
- The title is excluded. `annotations` is hashed as sent, including `annotations.title`. `outputSchema`, `icons` and `_meta` are not hashed.
- The result must equal `AlpacaBot\Mcp\ToolDefinition::fingerprint()` for the same four values. A test pins a known digest, so the plugin's stored pins keep matching.

**Hints.** The spec treats annotations as untrusted and supplies defaults when a hint is absent. A hint counts only when it is a bool.
- `readOnly()` = `readOnlyHint === true`.
- `destructive()` = `!readOnly() && destructiveHint !== false`, so an unannotated tool counts as destructive.
- `idempotent()` = `!readOnly() && idempotentHint === true`.
- `openWorld()` = `openWorldHint !== false`.

Each docblock says these values are for deciding when to ask a human, never for granting anything. Alpaca Bot's own `destructive()` deliberately uses a narrower rule (exactly `true`) and is unaffected.

**Naming.** `McpToolName::fit()` is Alpaca Bot's `ToolName::fit()` rule:
- A name made only of `[A-Za-z0-9_-]` that starts with a letter or `_` and is at most 64 characters comes back unchanged.
- Otherwise, disallowed characters become `_`, and an `_` is prepended if the first character is not a letter or `_`.
- The result is cut to 55 characters, followed by `_` and the first 8 hex characters of `sha256(raw)`.

`McpToolkit`'s default namer is `McpToolName::fit("{$prefix}__{$name}")`, or just `fit($name)` when the prefix is `''`. A host that needs another scheme passes `$namer` (coqui's is `mcp_<server>_<tool>`).

### 2. `McpClient`: the wire

The client only calls `tools/list` and `tools/call`, over Streamable HTTP, and only to `McpServer::$url`.

**Every request**
- A POST with one JSON-RPC message.
- Headers:
  - `Content-Type: application/json`;
  - `Accept: application/json, text/event-stream`;
  - `MCP-Protocol-Version`, once the version is known;
  - everything in `McpServer::$headers`.
- JSON-RPC ids are integers counted up per instance. Responses are matched on id, and any message carrying `method` is skipped, never taken as the response.

**Responses**
- `application/json` is decoded directly.
- `text/event-stream` is parsed for `data:` lines. Notifications and server requests are skipped until the response with the matching id arrives.
  - The existing `Provider\SseStreamParser` can't be used as it stands: it drops invalid JSON and doesn't track ids. The plan either extends it or adds a small MCP-specific parser. That choice is the plan's, and the decision is written there.
- Any other content type is an `McpProtocolException`.

**Limits and redirects.** The client enforces its own limits even if a host wrapper overrides its options:
- `max_redirects: 0` is passed, so the client never asks for a follow, and any 3xx status throws `McpRedirectException`. A host wrapper may drop that option, so the client also refuses an answer that a follow produced anyway: `redirect_count` above zero throws the same exception with a null Location, on whatever status the redirect target answered with (amendment 5, 2026-09-22). It can refuse the answer; it cannot un-send the request, which is why the wrapper must keep the option.
- `timeout` is passed as the per-request idle timeout.
- `max_duration` is also passed and also set to `McpServer::$timeout`, so it caps the whole request.
- An `on_progress` callback throws once the declared or received size passes `maxResponseBytes`. The body is also measured after it is read, so a wrapper that drops the callback still gets `McpTransportException`.
- Transport failures (the contracts' `TransportExceptionInterface`, including timeouts) become `McpTransportException`.

**HTTP status mapping**

| Status | Result |
| --- | --- |
| 2xx | Parsed |
| 202 | Accepted, but only for the `notifications/initialized` POST |
| 3xx | `McpRedirectException` |
| any status reached by a followed redirect (`redirect_count` > 0) | `McpRedirectException`, Location null |
| 401, 403 | `McpAuthException` |
| 400 | Negotiation rules below, otherwise `McpRpcException` if the body carries a JSON-RPC error, otherwise `McpProtocolException` |
| 404 | Session rule below, otherwise `McpRpcException` for a JSON-RPC error body, otherwise `McpTransportException` |
| Other 4xx, 5xx | `McpTransportException` |

Exception messages name the method and status. They never contain a header value or a session id, and, for header values given as strings as `McpServer::$headers`' `array<string, string>` documents, neither does the message of an exception McpClient's exceptions carry as their previous (amendment 16, 2026-09-24: this promise named the messages alone. A header value holding CR, LF or NUL — a token read from a file with its trailing newline, say — made the HTTP client refuse the request with an exception whose message quotes the whole header line, and HttpExchange chained it: `McpTransportException: "MCP tools/list failed with a transport error."` carried `InvalidArgumentException: "Invalid header: CR/LF/NUL found in "Authorization: Bearer sk-live-123\r\nX: y"."` as its previous (reproduced by the controller at 330f2e9 with `HttpClient::create()`; the same for `Bearer sk-live-123\0`, for a header name holding CR or LF, and for a session id a store hands back holding LF). HttpExchange now refuses a header name or value holding CR, LF or NUL, across every header it sends whose value is a string, before calling the client, with an `McpTransportException` that names the method alone and has no previous; `McpServer`'s constructor is unchanged. An array value is outside that check, and the code is not changed for it (controller's ruling on the F2b review): measured, `['Authorization' => ["Bearer sk-live-123\n"]]` passes the check with PHP's "Array to string conversion" warning, and the previous again quotes the whole header line. A `Stringable` value is converted to its string by the check, and refused when that string holds CR, LF or NUL (amendment 15, 2026-09-24: these two sentences said "A value of another type is outside that check", and a `Stringable` is not outside it. Probed through `McpClient` against `MockHttpClient`: a `Stringable` `Authorization` whose string is `Bearer sk` followed by LF, CR or NUL gives `McpTransportException: "MCP tools/list request has a header name or value holding CR, LF or NUL."`, with no previous and no request sent). The previous a transport failure still chains can quote the URL or the host: measured, a query token or userinfo password in `McpServer::$url` reaches it through CurlHttpClient, and a query token through NativeHttpClient. That is not a header value). A server's own JSON-RPC error text is spliced into `McpRpcException`'s message, and a server can reflect a credential back in it, so `McpClient` redacts every configured header value, the form trimmed of SP, HTAB, LF, VT, FF and CR and the credentials part of an `Authorization` or `Proxy-Authorization` value, and every session id this client instance has held from that text before it builds the exception (amendment 3, 2026-09-21; Task 12 owns the redaction. amendment 7, 2026-09-22: "the current session id" was narrower than the code. `McpClient::hold()` records an id as the store hands an entry over and as an `initialize` reply arrives, whatever the client then does with either, and nothing empties that list — `forget()` stops the client *sending* an id, so an id it has stopped sending is still redacted if the server echoes it back; amendment 13, 2026-09-24: this sentence said "every configured header value and every session id" alone, and the whole value was the only needle a header gave. A server can echo a token without its scheme: on the 2025-11-25 path, with `Authorization: Bearer sk-live-123` configured, an `initialize` answered with JSON-RPC error -32001 reading `invalid token sk-live-123 (sent Bearer sk-live-123)` gave the message `MCP initialize failed with JSON-RPC error -32001: invalid token sk-live-123 (sent [redacted])` (Kanboard #4437, reproduced against MockHttpClient). For `Authorization` and `Proxy-Authorization`, the name matched case-insensitively, the value is also taken trimmed at both ends of SP, HTAB, LF, VT, FF and CR — the bytes PCRE's `\s` matches. That trimmed value is a needle when it is not empty, and when it is an RFC 9110 §11.4 auth-scheme token, then one or more of those bytes, then at least one more byte, the rest after them — the credentials part, every byte inside it kept — is a needle too. The configured value stays a needle, and strtr() tries the longer needle first, so `Bearer sk-live-123` becomes one `[redacted]`. `Bearer`, `Bearer` followed by those bytes alone, and a value whose trimmed form does not open with a token followed by one of them give no credentials part. Other headers are neither trimmed nor split: `X-Api-Key: Token abc123` redacts `Token abc123` and leaves a bare `abc123`. An echo the server decodes or otherwise transforms is not caught: with `Authorization: Basic dXNlcjpwYXNz`, the server text `bad credentials user:pass` reaches the message unchanged. The trimming and the `\s` class are the controller's rulings on the Phase F review (Kanboard #4437). This amendment first took the value exactly as configured and separated the scheme on SP alone; through McpClient against a Node server, which reads `Bearer sk-live-123 `, ` Bearer sk-live-123` and `Bearer sk-live-123\t` alike as `Bearer sk-live-123` and echoed `invalid token ${token} (sent ${header})`, those three values gave `invalid token [redacted](sent Bearer sk-live-123)`, `invalid token sk-live-123 (sent[redacted])` and `invalid token sk-live-123 (sent Bearer sk-live-123)`. A second draft trimmed and split on SP and HTAB alone, and McpClient sends VT and FF as configured: a Python http.server handler that reads the token with `str.split()` answered `Bearer sk-x\x0B`, `\x0BBearer sk-x` and `Bearer\x0Csk-x` with `invalid token sk-x`, which reached the message. What remains: McpClient also sends 0x1C to 0x1F, 0x85 and 0xA0 as configured, `str.split()` splits on each, and that handler's `invalid token sk-x` for `Bearer sk-x\x1C`, `\xA0Bearer sk-x` or `Bearer\x85sk-x` reaches the message; LF and CR never reach a server, because a value holding either fails with `McpTransportException` before any connection is opened).

That promise is about exception **messages**. `McpRpcException::$data` is passed through as the server sent it, deliberately, because version negotiation reads `data.supported` from it; the consequence for a host is that logging `$e->data` logs unredacted server-supplied data (amendment 8, 2026-09-22).

The redaction can only remove an id the client received. A server that puts an `Mcp-Session-Id` header on a reply the client does not read that header from — a `tools/list` reply, the `notifications/initialized` ack, or any 2026-07-28 reply — and then names that id in its own error text will leak it, because the client never held it. That is not a defect: MCP assigns the session id on the `InitializeResult`, and `McpClient` reads the header from the `initialize` reply alone, so an id it never received, never stored and never sends is not the session id this section promises about (amendment 9, 2026-09-22).

**Protocol negotiation (Kanboard #4406)**
1. If a store entry or an in-memory value exists, or `$protocolVersion` is pinned, use that version. Otherwise send the request as **2026-07-28**:
   - `MCP-Protocol-Version: 2026-07-28`;
   - `Mcp-Name: <tool>` for `tools/call`, using the MCP spec's header value encoding, and `Mcp-Method: <method>` sent raw (amendment 6, 2026-09-22: this line said both went through the encoding; the line was what was wrong, not the code. `Internal\HeaderValue::encode()` returns `tools/list`, `tools/call`, `initialize` and `notifications/initialized` unchanged — measured on PHP 8.4.25 — so no request this client sends can tell the two readings apart on the wire, MCP 2026-07-28 Streamable HTTP §Request Metadata defines the `=?base64?…?=` sentinel for `Mcp-Name` and `Mcp-Param-*` and not for `Mcp-Method` — Value Encoding is written for `Mcp-Param-{Name}` and then extended with "The same encoding rule applies to the `Mcp-Name` header value", and Server Validation names those same two as the headers a server MUST decode before comparing them to the body — and `FakeMcpServer` compares `Mcp-Method` raw while decoding `Mcp-Name`);
   - `params._meta` containing `io.modelcontextprotocol/protocolVersion`, `io.modelcontextprotocol/clientCapabilities: {}` and `io.modelcontextprotocol/clientInfo {name: "php-agents", version: McpClient::CLIENT_VERSION}`. `src` has no version constant today, so `McpClient::CLIENT_VERSION` is a new public string constant that the release task sets to the tag version.
2. A 400 whose body is a recognised modern error is from a modern server:
   - `-32022` UnsupportedProtocolVersion: retry once with a version from `data.supported` that the client speaks, otherwise throw `McpUnsupportedVersionException` (amendment 15, 2026-09-24: the line is kept, and this records how the client reads it, since "retry once" does not say what a second `-32022` gets. A `-32022` that lists `2026-07-28` is retried once, with `2026-07-28`; a `-32022` that is not retried falls back to the step 3 handshake when it lists `2025-11-25`, and otherwise throws `McpUnsupportedVersionException`. So a server that answers `-32022` listing both versions twice costs one `2026-07-28` retry and then the `2025-11-25` handshake. The controller's ruling on the final review keeps that code. Probed with `FakeMcpServer`: `["2026-07-28"]` once gives `tools/list, tools/list` and a result; both versions twice give `tools/list, tools/list, initialize, notifications/initialized, tools/list`; `["2025-11-25","2099-01-01"]` once gives `tools/list, initialize, notifications/initialized, tools/list`; `["2026-07-28"]` twice and `["2024-11-05"]` once throw `McpUnsupportedVersionException`. `McpClientModernTest`'s "-32022 listing both versions twice is retried once, then falls back to the handshake" pins the both-versions case, and turns red when the fallback arm also requires `2026-07-28` to be absent).
   - `-32020` and `-32021`: throw `McpRpcException`.
3. Any other 400 (empty or unrecognised body) means fall back to **2025-11-25**:
   - POST `initialize` with `{protocolVersion: "2025-11-25", capabilities: {}, clientInfo}`;
   - keep `Mcp-Session-Id` from the response if present;
   - POST `notifications/initialized` and expect 202;
   - resend the original request with `MCP-Protocol-Version: 2025-11-25` and the session header.
   - If `initialize` answers with a version the client doesn't speak, throw `McpUnsupportedVersionException`.

   The WordPress MCP Adapter takes this path. Read at trunk `4ff9806`: `includes/Transport/Infrastructure/HttpSessionValidator.php`'s `validate_session_with_error_handler()` opens by answering a request that carries no `Mcp-Session-Id` through `McpErrorFactory::invalid_request()`, which composes the message `Invalid Request: Missing Mcp-Session-Id header` and builds the error with `McpErrorFactory::INVALID_REQUEST`. What this client needs from that is only that the code is none of the three step 2 recognises (`-32020`, `-32021`, `-32022`), so the reply falls through to "any other 400" and the fallback runs. On this path the client compares the code with `-32022`, `-32020` and `-32021` alone, and the Adapter's is none of them (amendment 15, 2026-09-24: this sentence said "The client compares no number on this path", which is false read on its own: `McpClient::modern()` reads the code of every 400 and compares it with `-32022` and then with `-32020` and `-32021` before it hands the reply back for the fallback. The Adapter's code has since been read: its `composer.lock` at `4ff9806` pins `wordpress/php-mcp-schema` `v0.1.3`, whose `src/Common/McpConstants.php` sets `INVALID_REQUEST = -32600`, read with `gh api` on 2026-09-24) (amendment 10, 2026-09-22: this line used to assert `-32600` and cite a four-line range in `HttpSessionValidator`. The line range is gone — it is a position in another repository that moves on its own, and nothing here pins it. The number is gone because it was not read: `McpErrorFactory::INVALID_REQUEST` is `WP\McpSchema\Common\McpConstants::INVALID_REQUEST`, from the separate `php-mcp-schema` package, described there as "Standard JSON-RPC error codes as defined in the specification"; a reader who wants the literal reads that constant in that package. The `-32003` in the session rules below is not an Adapter reading either, and is no longer written as one: it is the code `FakeMcpServer` sends for an unknown tool, asserted in `FakeMcpServerTest` and `McpClientLegacyTest` (`grep -rn '32003' src/ tests/`)).
4. Handling 2026-07-28 results:
   - `resultType` absent means complete. `"complete"` is used as is. Any other value is an `McpProtocolException`, except `"input_required"`.
   - `"input_required"` with only `requestState`: resend with a new id, the same name and arguments, and `requestState` echoed back, at most 3 times.
   - `"input_required"` with `inputRequests`: `McpProtocolException`, because the client declares no capabilities.
   - `ttlMs` and `cacheScope` are read and ignored.
5. `x-mcp-header` (2026-07-28, sent only in that version):
   - For each top-level property of a tool's `inputSchema` that carries a valid annotation, `tools/call` sends `Mcp-Param-<Name>: <value>`. Strings go as is, integers in decimal, booleans as `true`/`false`, and unsafe values base64-encoded. Null or absent values are omitted.
   - `listTools()` drops any tool whose annotation is invalid. That means empty, not an HTTP token, a duplicate, placed on a non-primitive or `number` property, or not reachable through `properties`.
6. `listTools()` follows `nextCursor` until it is absent, with a hard cap of 100 pages that throws `McpProtocolException`.
   - Each tool needs a string `name` and an object `inputSchema`; any other tool is skipped.
   - A missing `description` becomes `''`, and a missing `annotations` becomes `[]`.
   - `title` is the top-level `title`, then `annotations.title`, then null.
   - A server that ignores cursors (the Adapter) returns one page, and that is fine.

**Sessions (Kanboard #4405)**
- The store key is `sha256(url . "\n" . json_encode(headers sorted by name))`.
- The store receives `McpSession(protocolVersion, sessionId)` after detection or `initialize`, and is read before the first request of a new instance.
- On a 404 to a request that carried a session:
  - If the body has no JSON-RPC error, or its code is `-32001` or `-32005`, treat it as an expired session: `forget()`, run `initialize` again once, and retry the request once.
  - Any other JSON-RPC error on a 404 — an unknown tool, say, which `FakeMcpServer` answers with `-32003` — throws `McpRpcException` with no retry.
- The client never sends DELETE.
- With `null` for the store, state lives only in the instance.

**Result mapping (Kanboard #4409)**

Content:
- Text blocks are joined with a blank line.
- An embedded `resource` with `text` adds its text after a `[resource <uri>]` prefix.
- With no text blocks, `structuredContent` becomes pretty JSON, first among the parts, with mimeType `application/json` only when that JSON is the whole content. When other blocks are joined with it, the joined string is not JSON, so the mimeType is null instead (amendment 4, 2026-09-22; ruled by the Phase C session, recording what `ResultMapper` does, pending Carmelo's confirmation at merge).
- Other blocks become one-line placeholders:
  - `[image <mime>, <n> bytes]` and `[audio <mime>, <n> bytes]`, where n is the decoded size;
  - `[resource <uri> (<mime>), <n> bytes]` for a blob, and a bare `[resource <uri>]` for an embedded resource carrying neither `text` nor `blob` (amendment 4, 2026-09-22);
  - `[resource_link <name> <uri>]`;
  - `[<type> block]` for an unknown type.

  Every field a shape interpolates falls back rather than dropping the block, and the shape keeps its own spaces either way. There are five, which is all of them: `<mime>` is the literal `unknown` when the block carries no string `mimeType`; `<type>` is `unknown` when `type` is absent or is not a string, so the last shape reads `[unknown block]`; `<n>` is 0 when the base64 is absent or fails a strict decode; and `<uri>` and `<name>` are the empty string, so a `resource_link` carrying neither reads `[resource_link  ]` with both spaces, a blob resource with no `uri` reads `[resource  (<mime>), <n> bytes]`, and an embedded resource with no `uri` — or with no `resource` object at all — reads `[resource ]` (amendment 4, 2026-09-22).

Metadata: `metadata['mcp']` holds `content` (the blocks as sent), `structuredContent` (when present), `isError`, `bytes` (the content size before the cap) and `truncated`.

Errors:
- `isError: true` gives `ToolResult::error(<content>)->withErrorCode('mcp_tool_error')`. When the result has no content, the message is `The tool reported an error.`
- A JSON-RPC error throws `McpRpcException`.

Cap: content longer than `maxResultBytes` is cut with `mb_strcut`, followed by `\n[truncated: <shown> of <total> bytes]`. Truncation also clears the mimeType, so truncated JSON is not labelled `application/json` (amendment 4, 2026-09-22; same ruling).

Not done: validating `structuredContent` against `outputSchema`.

### 3. `McpToolkit`

- **Construction.** `$allow` maps a server tool name to its pinned fingerprint.
- **`tools()`.** Calls `listTools()` at most once per instance. For each listed tool:
  - not in `$allow`: skipped;
  - in `$allow` with a different fingerprint: withheld and collected as drifted;
  - otherwise: exposed as a `SchemaTool` whose name comes from the namer, whose description is the server's, whose schema is `inputSchema`, and whose closure calls `$client->callTool($serverName, $args)`.

  `$onDrift` is called once with the list of drifted definitions, when that list is not empty. Approved tools the server no longer lists are simply absent. If two tools end up with the same exposed name, the last one wins.
- **Errors.** An `McpException` from `listTools()` propagates to the caller, because the library doesn't guess a host's failure policy. A throw from inside a tool's `execute()` becomes an error `ToolResult` through `SchemaTool`.
- **`definition($exposedName)`** returns the live definition behind an exposed name, or null. It uses the same per-instance listing.
- **`guidelines()`** returns `''`. A server's `instructions` is untrusted text, and it is not passed to the model.
- **No cache across instances.** A host that builds a toolkit per turn sees a changed definition immediately.

### 4. Raw schemas across providers (Kanboard #4407)

- **`SchemaTool`**
  - `parameters()` returns `[]`, so `SystemPrompt::withTools()` renders no parameters block for it.
  - `execute()` runs the closure without validating the arguments, because the schema's owner does that. Any `Throwable` becomes `ToolResult::error("The <name> tool failed before it could answer.")` rather than the raw message, which could quote a URL.
  - `toFunctionSchema()` returns the usual `{type: function, function: {name, description, parameters}}`, with `parameters` = `JsonSchemaRepair::repair($schema)`. A root with no `type` becomes `object`.
- **`JsonSchemaRepair::repair()`** replaces an empty `[]` with `new \stdClass()`, but only where the value must be an object:
  - map-valued keywords: `properties`, `patternProperties`, `$defs`, `definitions`, `dependentSchemas`;
  - schema-valued keywords: `additionalProperties`, `unevaluatedProperties`, `items` (unless it is a non-empty list), `additionalItems`, `unevaluatedItems`, `contains`, `not`, `if`, `then`, `else`, `propertyNames`, `contentSchema`.

  It recurses into each member of a map-valued keyword, into each schema-valued keyword (into each member of a tuple `items`), and into each `anyOf`, `oneOf`, `allOf` and `prefixItems` entry. It does not enter draft-07 `dependencies`, whose values mix schemas with string lists, or any keyword not listed here. It never touches `enum`, `const`, `default`, `examples`, `required` or `type` (amendment 15, 2026-09-24: this said "It recurses into every subschema position, including `anyOf`/`oneOf`/`allOf` entries", and the schema-valued list lacked `additionalItems`, `unevaluatedItems` and `contentSchema`, which `JsonSchemaRepair` repairs. Probed: `{"type":"object","dependencies":{"a":{"properties":[]}}}` and `{"type":"object","x-schema":{"properties":[]}}` come back unchanged; `additionalItems: []`, `unevaluatedItems: []` and `contentSchema: []` each come back `{}`; a `{"properties":[]}` under each listed map member, schema-valued keyword, tuple `items` member and `anyOf`/`oneOf`/`allOf`/`prefixItems` entry comes back `{"properties":{}}`; `{"enum":[],"const":[],"default":[],"examples":[],"required":[],"type":[]}` comes back unchanged).
- **`OpenAIResponsesProvider` / `StrictSchemaNormalizer`**
  - Also normalise objects under `items`, under combinator branches, under `$defs`, and nullable (`type` array) objects.
  - Normalise an optional object before wrapping it in `anyOf`.
  - Send `strict: false` when the schema contains a free-form object (`type: object` with no `properties`, or `additionalProperties` that is `true` or a schema), a `$ref`, or `patternProperties`.
  - Native `Tool`s keep strict mode.
- **`GeminiProvider`**
  - Recurse into `properties`, `items` and each `anyOf` branch, except into the members of a `properties` map held as an object and of a draft-04 tuple `items`, the two places amendment 14 names in the bullet on stripping below (amendment 15, 2026-09-24: the exception was added here, where the bullet read as though the walk reached every member of both. Probed on the tool path: `{"type":"object","properties":{"0":{"type":"string","not":{"type":"integer"}}}}` comes back with that member unchanged, and a tuple `"items":[{"type":"string","not":{"type":"integer"}}]` likewise, while the same member under a named property or a single-schema `items` comes back `{"type":"STRING"}`) (amendment 11, 2026-09-22: this bullet used to say `anyOf`/`oneOf`/`allOf`, `$defs` and `items`. It does not recurse into `$defs` — `$defs` is in `UNSUPPORTED_KEYWORDS`, so the keyword is removed outright and there is nothing left to descend into. Probed: `{"type":"object","$defs":{"D":{"type":"string","default":"x"}},"properties":{"p":{"type":"string","default":"y"}}}` comes back as `{"type":"OBJECT","properties":{"p":{"type":"STRING"}}}`. And it does recurse into `properties`, which the bullet left out) (amendment 14, 2026-09-24: this bullet said each `anyOf`/`oneOf`/`allOf` branch. Gemini's `Schema` has `anyOf` and no field for `oneOf` or `allOf` — its v1beta proto was read for this — so `oneOf` and `allOf` joined `UNSUPPORTED_KEYWORDS` and are removed with their branches rather than recursed into (Kanboard subtask 6482; widened to `oneOf` and `allOf` on Kanboard #4437 comment 1382). Probed: `{"type":"object","properties":{"x":{"oneOf":[{"type":"string"}]},"y":{"anyOf":[{"type":"string","not":{"type":"integer"}}]}}}` comes back as `{"type":"OBJECT","properties":{"x":{},"y":{"anyOf":[{"type":"STRING"}]}}}`).
  - Map `type: [X, "null"]` to `type: X` plus `nullable: true`.
  - Strip unsupported keywords wherever that recursion reaches (amendment 11, 2026-09-22: "at every depth" was false, and the same phrase has now been corrected in `GeminiProvider`'s own docblock and in `docs/TOOLS-AND-TOOLKITS.md`. A subschema reached any other way — under `not`, `contains`, `if`/`then`/`else`, `propertyNames`, `prefixItems` — is passed through as the server wrote it. Probed: a `not` holding `$ref` and `default` came back byte-for-byte) (amendment 14, 2026-09-24: amendment 11's list of pass-through positions is no longer true. `not`, `contains`, `if`, `then`, `else`, `propertyNames` and `prefixItems`, with `oneOf`, `allOf`, `dependentSchemas` and `dependentRequired`, are in `UNSUPPORTED_KEYWORDS`, because Gemini's `Schema` has no field for them (Kanboard subtask 6482, widened on Kanboard #4437 comment 1382), so each is removed, with whatever it holds, from each node the walk reaches: the root, a `properties` member, `items` and an `anyOf` branch. The walk does not reach a member of a `properties` map held as an object, which `JsonSchemaRepair` makes on the tool path of a map keyed `"0"`, `"1"`, … in order, or a member of a draft-04 tuple `items`; there a stripped keyword stays, and so does a lower-case `type`. A child node left with nothing becomes `{}`. A property named `not`, `oneOf` or `if` keeps its name and is normalised, and `required: ["if"]` is untouched, because the strip acts on schema nodes and not on the `properties` map. `structured()` shares the normaliser and strips the same keywords from `responseSchema`. A subschema under a keyword that is neither walked nor stripped, such as `unevaluatedProperties`, is still passed through as the server wrote it. Probed: the `not` holding `$ref` and `default` is now removed; `{"type":"object","properties":{"o":{"type":"object","unevaluatedProperties":{"type":"string","default":"z"}}}}` comes back as `{"type":"OBJECT","properties":{"o":{"type":"OBJECT","unevaluatedProperties":{"type":"string","default":"z"}}}}`; on the tool path `{"type":"object","properties":{"0":{"type":"string","not":{"type":"integer"}}}}` comes back as `{"type":"OBJECT","properties":{"0":{"type":"string","not":{"type":"integer"}}}}`, and on both paths a tuple `"items":[{"type":"string","not":{"type":"integer"}},{"oneOf":[{"type":"string"}]}]` comes back unchanged).
- **All providers.** No tool-formatting path may throw on a `stdClass` anywhere inside `parameters`. That covers OpenAI Chat's `required` injection, Ollama's `sanitizeSchema()` and the LlamaCpp normaliser, which must also stop producing `properties: []`.
- **Regression corpus.** `tests/Fixtures/mcp-schemas/*.json` runs through every provider's tool formatting. Each case must not throw, and no map or schema position may encode as a JSON list. Responses and Gemini also get snapshot assertions.

### 5. Docs

- **`docs/TOOLS-AND-TOOLKITS.md`**
  - Rewrite the "Tool Execution Policies" example to the real signature, `shouldExecute(string $toolName, array $arguments): true|string` (`CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface`), replacing the stale `(ToolInterface, ToolCall): bool`. `tests/Unit/Docs/ToolPolicyDocTest.php` reads the return type off that interface by reflection, so the doc cannot drift from it again (amendment 10, 2026-09-22: the line citation this used to carry into `ToolExecutionPolicyInterface` is dropped for the same reason as the one in step 3 above — it is a position with nothing pinning it, while the reflection in that test pins the thing the sentence is about).
  - Add a policy that asks for confirmation when `$toolkit->definition($name)?->destructive()`.
  - Add an MCP section: building a client, the toolkit, pinning and drift, the session store, the error tree, the Strauss note (no Symfony class names in strings), and a short non-HTTP `McpClientInterface` adapter example for hosts with a STDIO client.
- **`docs/ARCHITECTURE.md`**: one paragraph and the `Mcp`/`Schema` namespaces.
- **`README.md`**: a feature line.
- **Release notes:** the repo keeps no CHANGELOG file, so the notes live in the GitHub release body (§7).

### 6. Tests (Kanboard #4411)

- **`tests/Support/Mcp/FakeMcpServer`**
  - The `MockHttpClient` callback. It runs in a modern mode (2026-07-28) or a legacy mode (2025-11-25, behaving like the WordPress Adapter).
  - It records every request.
  - It can be scripted with: JSON or SSE bodies (with notifications before the response), pagination, 3xx, 401/403, a 404 for a stale session, 404 with `-32003`, `-32022` with `supported`, `input_required`, oversize bodies (declared and actual), a transport timeout, and malformed JSON.
- **`tests/Unit/Mcp/*`** covers every rule in §2 and §3.
- **`tests/Unit/Tool/SchemaToolTest.php`** and **`tests/Unit/Schema/JsonSchemaRepairTest.php`** cover the new public classes.
- **The provider corpus** is described in §4.
- **The fingerprint test** pins a literal digest computed by the plugin's algorithm for a fixed definition, so a change on either side fails.
- **`tests/Integration/Mcp/McpLiveTest.php`**
  - Runs only with `PHP_AGENTS_MCP_URL` set (`PHP_AGENTS_MCP_HEADER` and `PHP_AGENTS_MCP_READONLY_TOOL` are optional), and otherwise skips with a message saying why.
  - Checks that the list is not empty, that fingerprints are stable across two fresh clients, and makes one read-only call.
- **Gates:** `composer test` and `composer analyse` green. CI is unchanged.

### 7. Release

- **Version.** 0.16.0 is a minor: new API and provider behaviour fixes, with no breaking change to existing public signatures.
- **Tag and release.**
  - Tag `v0.16.0` on `main` after the PR from `feat_mcp-client` merges.
  - Pushing a `v*` tag runs `.github/workflows/release.yml`. It runs `composer test` and `composer analyse`, builds the zip and tar.gz with checksums, and publishes the GitHub release; v0.15.2's release was authored by `github-actions[bot]` with only a "Full Changelog" body.
  - Once that run is green, `gh release edit v0.16.0 --notes-file` replaces the body with notes covering the contract, the provider fixes and the coqui/Alpaca Bot follow-ups.
  - `McpClient::CLIENT_VERSION` is `'0.16.0'` in the tagged commit.
- **Contract change since addendum 2** (amendment 12, 2026-09-24), for the Kanboard #4364 comment and the release notes:
  - `McpToolDefinition::fingerprint()` holds `serialize_precision` at `-1` around json_encode() and hashes `serialize()` of the canonical array when json_encode() returns false (§1 "Fingerprint", Alpaca Bot 3a2682e). Two kinds of digest change. A definition json_encode() refused used to hash to `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`, whatever it held, and now gets a digest of its own. A definition holding a float that json_encode() writes differently at the host's `serialize_precision` than at -1 now gets its -1 digest: at 17, `0.1` is written `0.10000000000000001` and `1e23` is written `9.9999999999999992e+22`; at 14, `9007199254740992.0` is written `9.007199254741e+15`. At `serialize_precision` -1, a definition json_encode() accepts keeps the digest it had. The pins V1 (`4570eec8…5049`) and V4′ (`4c31fcb4…5ad9c8`) are unchanged.
  - Residuals, true of both repos: `{}` and `[]` hash the same; an empty `annotations` and a missing one hash the same; the number literals `9007199254740992.0` and `9007199254740993.0` hash the same, as do `99999999999999999999` and `100000000000000000000`, because each pair decodes to one float.
  - A header value given as a string that holds CR, LF or NUL is refused before the HTTP client is called, with an `McpTransportException` reading `MCP <method> request has a header name or value holding CR, LF or NUL.` and no previous (§2, amendment 16). The class is unchanged. The message used to read `MCP <method> failed with a transport error.`, and its previous was the HTTP client's exception quoting the whole header line. No request reached the server either way. Measured on 2026-09-24 through `McpClient` against `MockHttpClient` with `Authorization: Bearer sk-live-123` followed by LF, on this branch and with `HttpExchange` as it stood before `4e789bc` (amendment 15, 2026-09-24).
  - `McpRpcException`'s message also redacts an `Authorization` or `Proxy-Authorization` value trimmed of SP, HTAB, LF, VT, FF and CR, and its credentials part (§2, amendment 13). An `initialize` refused with `invalid token sk-live-123 (sent Bearer sk-live-123)` used to give a message ending `invalid token sk-live-123 (sent [redacted])` with `Authorization: Bearer sk-live-123` configured, and `invalid token sk-live-123 (sent Bearer sk-live-123)` with ` Bearer sk-live-123` plus HTAB configured; both now end `invalid token [redacted] (sent [redacted])`. Measured on 2026-09-24 through `McpClient` against `MockHttpClient`, on this branch and with `McpClient` as it stood before `ce24d3c` (amendment 15, 2026-09-24).
- **Manual check.** Before the tag, the live test runs once by hand against the WordPress MCP Adapter v0.6.1 on a throwaway wp-harness site, with an application password in the header. Its output goes in the PR.
- **After the tag.**
  - Comment on Alpaca Bot Kanboard #4364 and message the Alpaca Bot planning session, since its Task 28 is waiting on the tag.
  - Comment on coqui Kanboard #4432, which is blocked on the same tag.

## Constraints on the plan

- **Models.** All Opus: implementer, fixer and reviewer. Tickets carry `implementer_model: opus` and `reviewer_model: opus`, and every subagent dispatch passes `model: "opus"`. Never Haiku, never Sonnet.
- **TDD.** Write a failing Pest test first and watch it fail, then write the minimum code, then see `composer test` and `composer analyse` pass, then commit. Keep commits small and frequent, with no attribution lines.
- **No new Composer dependency.** `composer.json`'s `require` block is unchanged at the tag.
- **Strauss safety.** No Symfony (or any vendor) class name inside a string. Catch the contract exception interfaces.
- **Named review target: false load-bearing comments** (Alpaca Bot Kanboard #2978 comment 637). Every reviewer brief names both mechanisms:
  1. a sentence that argues from a counterfactual about the rejected alternative, or claims a completeness the code doesn't have ("its one caller", "every X goes through");
  2. a sentence that was true when written and was silently falsified by a later change.

  Reviewers check load-bearing comments against the file, a command they run, or the upstream source at its pinned version (MCP spec 2025-11-25 / 2026-07-28; the Adapter at trunk `4ff9806`). Fixers re-read nearby sentences after adding a mechanism, and list the false sentences they caught in their own drafts before committing.
- **Board.** Never set `approved_by`. Never run `kanboard-run-milestone.sh`. Log time by hand in comments.

## Non-goals

- OAuth of any kind (discovery, PKCE, token storage).
- STDIO, and the HTTP+SSE transport from 2024-11-05 / 2025-03-26 (the GET fallback).
- SSE resumability (`Last-Event-ID`), GET streams, `subscriptions/listen`.
- Prompts, resources (`resources/read`), sampling, elicitation, roots, logging, tasks, and `inputRequests` in multi-round-trip requests.
- Hosting an MCP server.
- Anything WordPress-specific: SSRF pinning, token storage, settings UI, capabilities.
- Validating arguments against raw schemas inside php-agents, and validating `structuredContent` against `outputSchema`.
- Lossless schema flattening for Ollama/LlamaCpp, and a tool-list cache that outlives one toolkit instance.
- Any edit to the Alpaca Bot or coqui repositories.

## Sequencing

1. **Groundwork:** `JsonSchemaRepair`, then `SchemaTool`, then the provider regression corpus and the Responses, Gemini and no-throw fixes.
2. **Value objects and errors:** `McpServer`, `McpToolDefinition` (fingerprint and hints), `McpToolName`, `McpSession`/`McpSessionStore`, the exception tree.
3. **Test seam:** `FakeMcpServer`.
4. **Client, legacy path first:** the 2025-11-25 handshake, sessions, JSON and SSE bodies, status mapping, limits, `listTools` pagination, `callTool` result mapping.
5. **Client, modern path:** the 2026-07-28 probe and fallback, `_meta` and headers, `resultType`, `input_required`, `x-mcp-header`, the version pin, store persistence of the detected version.
6. **`McpToolkit`:** allowlist, drift, namer, `definition()`.
7. **Docs,** including the stale policy example, and the env-gated live test.
8. **Release:** version, the manual Adapter run, the PR, the `v0.16.0` tag and GitHub release, and the notifications on Alpaca Bot Kanboard #4364, the Alpaca Bot planning session and coqui Kanboard #4432.
