# 0007: Structured output can be streamed through the opt-in `StructuredStreaming` interface

- Status: accepted (v0.21.0, 2026-11)

## Context

A host that shows a structured answer while it is generated (a list of subtasks filling in, a
summary card typing out) needs the request `structured()` sends, streamed. `structured()` blocks
until the document is complete. `streamEvents()` streams, but a caller that wants schema-constrained
output would have to rebuild each provider's structured request itself: the `response_format`
envelope and strict normalisation on OpenAI-compatible servers, the forced function on the
Responses API, `output_config.format` or the instructed fallback on Anthropic, and `responseSchema`
on Gemini. Rebuilding them outside the provider would drift from `structured()`.

The host pins the provider's HTTP client to vetted addresses, so a streamed structured call must
go through the same injected client and the same base URL as every other request.

## Decision

`Contract\StructuredStreaming` is a separate interface, like `UsageReporting`:

```php
public function structuredEvents(array $messages, string $schema, array $options = []): iterable;
```

`ProviderInterface` is unchanged, so third-party providers and decorators keep compiling. Callers
feature-detect with `instanceof StructuredStreaming`. `OpenAICompatibleProvider` (and so
`OllamaProvider`, `XAIProvider`, `MistralProvider` and `LlamaServerProvider`),
`OpenAIResponsesProvider`, `AnthropicProvider` and `GeminiProvider` implement it. `CliProvider`
does not.

`structuredEvents()` builds the same request `structured()` builds, from the same private
method, and sends it through the provider's own `streamEvents()`. The schema shapes `structured()`
accepts (a bare schema, or a `{name, schema}` / `{name, parameters}` envelope) are accepted the
same way, and a schema that is not JSON falls back to an unconstrained request as `structured()`
does.

The stream yields:

| Event | Meaning |
| --- | --- |
| `StructuredDelta` | One fragment of the JSON document. Concatenated, the fragments are the document |
| `ReasoningDelta` | Reasoning, where the provider sends it |
| `Tick` | The stream has been idle for the tick interval |
| `Finish` | Exactly one, last. `Finish(Stop)` means the document is complete |

`StructuredDelta` is its own event, not `TextDelta`, so a consumer cannot mistake a JSON fragment
for prose. Nothing validates the document against the schema; the caller decodes and validates it.

On the Responses API the document is the forced function's arguments: their deltas become
`StructuredDelta`, and the response's tool-use finish becomes `Finish(Stop)` once that function's
call has ended. The function is never executed. Text and other tool calls are not part of the
document and are dropped; a tool-use finish whose forced call never ended becomes `Finish(Error)`.
On the other providers the document is the reply text, and tool events are dropped.

Usage reports name the operation `structured_stream`. Abandoning the generator cancels the HTTP
request through `cancelOnAbandon()`, as `streamEvents()` does. Options reach the request body only:
none of them can set the URL or replace the client.

## Consequences

- A decorator that wraps a provider hides `structuredEvents()` unless it implements the interface
  too. ADR 0004 put `streamEvents()` on `ProviderInterface` for that reason; here the caller
  feature-detects and can fall back to `structured()`, so the interface stays opt-in.
- A refusal streamed as text on an OpenAI-compatible server arrives as `StructuredDelta` followed
  by `Finish(Refusal)`; only `Finish(Stop)` marks a document.
- Request-building changes to `structured()` apply to `structuredEvents()` with no second edit.
