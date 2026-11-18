# 0003: Provider requests are bounded per request and follow no redirect

- Status: accepted (v0.17.0, 2026-11)

## Context

A security review of the providers found three gaps:

- **M1.** Provider requests had a 300 s *idle* timeout (60 s for embeddings) and no total limit
  or byte cap. A slow upstream that drips one SSE byte at a time holds a PHP worker open with no
  upper bound, and an unbounded body or an SSE line with no newline grows until `memory_limit`.
- **M2.** `GeminiProvider` downloads `image_url` URLs on the server. It fetched any host,
  including `169.254.169.254`, loopback and the intranet, followed up to 5 redirects (so a public
  URL could bounce into the private range), and read the body without a cap.
- **M3.** The default client followed redirects. symfony/http-client strips only `Authorization`
  and `Cookie` on a cross-host redirect, so Anthropic's `x-api-key` and Gemini's `x-goog-api-key`
  were re-sent to whatever host a 3xx named, with the conversation replayed on 307/308.

Hosts inject their own HTTP client (AiConnector passes an SSRF-pinned one), so limits set as
defaults on our `HttpClient::create()` would not apply to them. The MCP client already solves
the same problem per request (ADR 0001).

## Decision

- One value object, `Provider\RequestLimits`, carries `idleTimeout` 300.0 s, `maxDuration`
  600.0 s, `maxResponseBytes` 33_554_432 (32 MiB) and `maxRedirects` 0. `apply()` turns them into
  **per-request** options (`timeout`, `max_duration`, `max_redirects`, and an `on_progress` check
  that refuses an over-cap body or a followed redirect), so they hold with an injected client.
  `maxDuration` 0.0 means no total limit; `maxResponseBytes` 0 means no cap.
- Every provider and embedding request goes through `apply()`. `AbstractProvider` and both
  embedding providers expose `withRequestLimits()` (returns a copy) and `requestLimits()`.
  Embedding providers default to `idleTimeout` 60.0. `isAvailable()` keeps a 5 s idle timeout;
  Anthropic and Gemini `models()` and Ollama `/api/show` keep 10 s.
- Ollama `/api/pull` uses `maxDuration` 0.0 and `maxResponseBytes` 0, because a model download
  runs far longer than 600 s; it keeps the idle timeout and still refuses redirects.
- **No redirects.** A 3xx is never followed. It becomes `ProviderException::redirectRefused()`,
  whose message says "redirect not followed (...)". `SseStreamParser` refuses a 3xx before
  reading any data, lets a 4xx or 5xx propagate unchanged so the agent still reads the API error
  body, and caps an unterminated line at 1_048_576 bytes.
- **Private-network block on server-side fetches.** Gemini's image download accepts only
  `http(s)`, resolves the host and refuses it unless every resolved address passes
  `FILTER_FLAG_GLOBAL_RANGE`, and denies the `64:ff9b::/96` NAT64 prefix explicitly, because it
  passes PHP's filter. The checked address is pinned through the `resolve` option. Up to 3
  redirects are followed by hand, each hop checked and pinned again. Each hop is limited to 10 s
  idle, 30 s total and 20 MiB, and only a 2xx `image/*` answer is kept; anything else sends the
  existing "could not download" text part. This is hand-rolled rather than Symfony's
  `NoPrivateNetworkHttpClient`, which needs `symfony/http-foundation`: no new dependency.

## Consequences

Three behaviour changes, hence a minor release:

1. A provider request or stream running longer than 600 s in total is aborted (previously
   unbounded). Configure it per instance with `withRequestLimits()`.
2. Providers follow no redirect. A base URL that answers 3xx, even `http://` to `https://` on the
   same host, now fails with a message naming the redirect instead of being followed. Point the
   base URL at the final address.
3. Gemini no longer downloads image URLs that resolve to private, loopback, link-local or reserved
   addresses; the model gets the "could not download" text part instead.

Known limits of the Gemini image check:

- When `HTTP_PROXY` or `HTTPS_PROXY` is set, the proxy resolves the host again, so the `resolve`
  pin does not apply and the proxy's own egress rules decide what is reachable.
- The 30 s total limit is per hop, not for the whole download, so a download with 3 redirects can
  take up to 120 s.
