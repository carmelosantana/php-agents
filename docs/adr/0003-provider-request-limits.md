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
  that refuses an over-cap body or a followed redirect). They hold with an injected client that
  honours symfony/http-client's `max_redirects`, `max_duration`, `on_progress` and `resolve`
  options. A client that ignores them does not get them; Coqui's ReactPHP adapter is the known
  case, and fixing it belongs to Coqui. The Gemini image download uses its own client, so its
  guard always holds.
  `maxDuration` 0.0 means no total limit; `maxResponseBytes` 0 means no cap.
- Every HTTP request goes through `apply()`. `CliProvider` sends none. `LlamaCppProvider` sends
  none itself, and `LlamaCppMultimodalNormalizer` refuses a remote `image_url` unless the host
  configures an `ImageResolverInterface` (`RemoteImageResolver` carries its own limits) or the
  legacy `$remoteImageFetcher` (finding L2, closed). `AbstractProvider` and both embedding providers expose `withRequestLimits()` (returns a copy) and `requestLimits()`.
  Embedding providers default to `idleTimeout` 60.0. `isAvailable()` keeps a 5 s idle timeout;
  Anthropic and Gemini `models()` and Ollama `/api/show` keep 10 s.
- Ollama `/api/pull` uses `maxDuration` 0.0 and `maxResponseBytes` 0, because a model download
  runs far longer than 600 s; it keeps the idle timeout and still refuses redirects.
- **No redirects.** A 3xx is never followed. For `chat()`, `stream()` and `structured()` it
  becomes `ProviderException::redirectRefused()`, whose message says "redirect not followed (...)".
  Embedding providers, `isAvailable()` and `models()` report it or fall back as
  `docs/PROVIDERS.md` describes under "No Redirects". `SseStreamParser` refuses a 3xx before
  reading any data, lets a 4xx or 5xx propagate unchanged so the agent still reads the API error
  body, and caps an unterminated line at 1_048_576 bytes.
- **Private-network block on server-side fetches.** Gemini's image download goes through a
  library-owned `HttpClient::create()` (or the `imageHttpClient` constructor argument, a test
  seam), never the provider's API client, because the guard needs `resolve`, `max_redirects` and
  `on_progress` to be honoured. It accepts only `http(s)`, resolves the host and refuses it
  unless every resolved address passes `FILTER_FLAG_GLOBAL_RANGE` and is outside an IPv6
  deny-list of ranges holding addresses PHP's filter accepts: `64:ff9b::/96` and
  `64:ff9b:1::/48` (NAT64), `fec0::/10` (site-local), `ff00::/8` (multicast) and
  `::/8` (reserved). `::/8` covers IPv4-compatible `::/96` (such as `::7f00:1` and `::a00:1`),
  IPv4-translated `::ffff:0:0:0/96` and
  IPv4-mapped `::ffff:0:0/96`, which PHP's filter already refuses. The checked address of a host name is pinned through the `resolve` option;
  an IP-literal host has no DNS to rebind and gets no pin. Up to 3 redirects are followed by
  hand, each hop checked and pinned again. Each hop is limited to 10 s idle, 30 s total and
  20 MiB, and only a 2xx `image/*` answer is kept; anything else sends the
  existing "could not download" text part. This is hand-rolled rather than Symfony's
  `NoPrivateNetworkHttpClient`, which needs `symfony/http-foundation`: no new dependency.

## Consequences

Behaviour changes, hence a minor release:

1. A provider request or stream running longer than 600 s in total is aborted (previously
   unbounded). Configure it per instance with `withRequestLimits()`.
2. Providers follow no redirect. A base URL that answers 3xx, even `http://` to `https://` on the
   same host, now fails with a message naming the redirect instead of being followed. Point the
   base URL at the final address.
3. Gemini no longer downloads image URLs that resolve to private, loopback, link-local or reserved
   addresses; the model gets the "could not download" text part instead.
4. Gemini refuses an image answer with a missing or non-`image/*` content type; it no longer
   guesses the type from the file extension. Its redirect budget drops from 5 to 3.
5. The 32 MiB `maxResponseBytes` cap counts a stream's whole body, not one event, so a very long
   stream is aborted. Raise it with `withRequestLimits()`.
6. The per-request `timeout` replaces an injected Symfony client's own default, and so does
   `max_duration` when `maxDuration` is above 0.0. With `maxDuration: 0.0` no `max_duration` is
   sent and the client's own `max_duration` default applies (none for `HttpClient::create()`).
   `withRequestLimits()` is the way to change them.
7. Gemini image downloads no longer go through the host's HTTP client, so that client's proxy
   and other settings do not apply to them; they use a library-owned client, which still reads
   the `HTTP_PROXY` and `HTTPS_PROXY` environment variables.

Known limits:

- An injected client that ignores `max_redirects`, `max_duration`, `on_progress` or `resolve`
  (Coqui's ReactPHP adapter) gets none of the limits those options carry.
- `gethostbynamel()` and `dns_get_record()`, which the Gemini image check calls, block and are
  not covered by the request limits.
- A host-supplied `ImageResolverInterface` or legacy `$remoteImageFetcher` for llama.cpp images is
  outside `RequestLimits`; its limits are its own.

Known limits of the Gemini image check:

- When `HTTP_PROXY` or `HTTPS_PROXY` is set, the proxy resolves the host again, so the `resolve`
  pin does not apply and the proxy's own egress rules decide what is reachable.
- The 30 s total limit is per hop, not for the whole download, so a download with 3 redirects can
  take up to 120 s.
