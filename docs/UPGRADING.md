# Upgrading

## Upgrading to 0.18

0.18 streams typed events from every provider (see [Streaming Events](PROVIDERS.md#streaming-events))
and adds a `Cancelled` finish reason. Most applications that only use the bundled providers and
`Agent` need no change; the breaking changes fall on custom providers, direct `stream()` callers,
usage tallies and exhaustive matches on `AgentFinishReason`.

### Breaking changes

| Change | Who is affected | What to do |
|---|---|---|
| `ProviderInterface::streamEvents()` added | Every class that implements `ProviderInterface` directly: custom providers, decorators, test doubles | Add `use StreamsEventsFromResponses;` to keep building on `stream()`, or forward from the wrapped provider (below) |
| `AbstractProvider::stream()` is `final` and `streamEvents()` is abstract; the agent iterates `streamEvents()` | Every subclass of `AbstractProvider`: a 0.17 subclass defines `stream()`, which now fails as an override of a final method | Implement `streamEvents()` natively and delete the `stream()` override; `stream()` is projected from the events. The `StreamsEventsFromResponses` trait is never on an `AbstractProvider` subclass: it throws a `LogicException` there |
| `AgentFinishReason::Cancelled`; a cancel no longer returns `Error` (or `Stop` mid-stream) | Exhaustive `match` on the enum; code that treats a cancel as an error | Add the case. `agent.error` with `'Task cancelled'` still fires on every cancel |
| `stream()` is normalised: one chunk per delta, then exactly one terminal chunk with the finish reason, usage, model and tool calls | Direct `stream()` callers on the OpenAI-compatible, Gemini and Responses providers | Read the finish reason, usage and model from the last chunk. Delta chunks carry no model. Summing usage across chunks still works |
| Anthropic `promptTokens` includes cache reads and writes | Cost and usage tallies | Subtract `cacheReadTokens` and `cacheWriteTokens` for the old figure |
| Gemini `completionTokens` includes thought tokens; Responses `totalTokens` is the provider's total; `chat()` usage is normalised the same way as streaming, and `null` when the provider reports no counts | Usage tallies | Read `reasoningTokens` and the cache fields for the old split |
| Tool arguments that are not a JSON object drop the call, on every provider, in `stream()` and `chat()`. A response that would have finished `ToolUse` finishes `Error`; another reason, such as `MaxTokens`, is kept. Empty arguments and an empty object are `{}` | Callers that relied on the `[]` fallback (OpenAI-family streams, every provider's `chat()`) | None for valid models; truncated calls no longer run |

A class that implements `ProviderInterface` and already has a `stream()`:

```php
use CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

final class MyProvider implements ProviderInterface
{
    use StreamsEventsFromResponses;

    // chat(), stream(), structured(), … unchanged
}
```

A decorator forwards the events, so the agent behind it keeps tool-input events and idle ticks:

```php
public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
{
    yield from $this->inner->streamEvents($messages, $tools, $options);
}
```

### Other behaviour changes

- A provider stream that ends without a finish is a provider error: the run ends
  `AgentFinishReason::Error` with `'Provider error: the response ended without a valid finish reason.'`.
- llama.cpp: `chat()` reports `ToolUse` where a trailing usage-only chunk used to overwrite it with
  `Stop`, and a stream that ends without a finish reason finishes `Stop`.
- A mid-stream cancel ends `Cancelled`. `Output->content` is the text streamed so far, followed by
  the `done` answer previewed so far, and that text is added to the conversation; with neither it
  is `'Task was cancelled.'`. A provider exception, or a stream that ends without a finish, once
  the token is set (a cancel that aborts the transport) ends the same way, not `Error`. So does a
  cancel that lands after a text, `done` or empty response finished but before it is accepted or
  retried (on `agent.step_finish`, `agent.budget_warning` or `agent.empty_response`, say): no
  `agent.done`, finish `Cancelled`. Refusal, content-filter, context-window and provider-error
  finishes keep their own reason. A cancel during tools gives every call that did not start a
  `'Cancelled before it ran.'` result with `errorCode` `'cancelled'`. Usage of a finish that
  arrived before a cancel or a streaming run limit abandoned the stream is counted in
  `Output->usage` and reported to the context window. See [Cooperative
  Cancellation](AGENTS.md#cooperative-cancellation).
- `agent.finish` fires once on every exit path that returns. An exception that escapes `run()`
  gets none.
- `ImagePolicy` (and so `LocalImageResolver`, `RemoteImageResolver` and the image normalizers)
  works without ext-fileinfo: it sniffs with `getimagesizefromstring()` instead of failing with
  `Class "finfo" not found`. That fallback knows image formats only, so non-images sniff as `unknown`.
- In `Conversation::estimateTokens()` (and so `maxInputTokens` and `maxEstimatedTokens`) an image part
  counts as `Conversation::IMAGE_TOKENS` (1,000) instead of one token per 4 bytes of its base64. A
  workaround that raised `maxInputTokens` to fit images can go.
- `RunLimits::$maxToolCallsPerTurn` is applied while the response streams. A call past the cap is
  discarded as it opens (`agent.tool_input_discarded` reason `tool_call_limit`) and is no longer
  kept in the conversation with a `Tool call limit reached: at most N calls per turn.` error
  result; `agent.warning` still fires once with that text. A second `done` call in one response now
  counts toward the cap.
- `RunLimits::$maxResponseBytes` also counts tool call ids and names, each call's final argument
  JSON beyond its streamed deltas, call metadata, and the finish's raw reason and model, so a
  run with a tight byte cap reaches it slightly sooner.
- `agent.step_finish` and `agent.finish` pass the provider's raw finish reason and model id through
  `UntrustedText::forDisplay()`: credential shapes become `[redacted]`, control and bidi characters
  become spaces, whitespace is collapsed and trimmed, and the text is cut to 128 characters plus a
  `…` (129 at most). Compare `StepFinish::$rawReason` with the normalised `reason`, not with a raw
  value. `Finish` from `streamEvents()`, `Output::$model` and `Response::$model` stay verbatim.
- `UntrustedText::forDisplay()` (provider and tool error text, finish telemetry) also redacts a
  credential split by a control or format character, and `redactSecrets()` (MCP results too) a key
  glued to `_` or punctuation, such as `reason_sk-…`. `scrub()` also blanks the Hangul fillers and
  never cuts a letter off its combining mark.
- `SseStreamParser` applies its 1 MiB line cap (`maxLineBytes`) to every line, not only to one
  still waiting for its newline: a provider stream with a longer complete line now throws a
  `ProviderException` (`SSE line exceeded … bytes`) instead of decoding it.

### New and not breaking

- Agent events: `agent.tool_input_start`, `agent.tool_input_delta`, `agent.tool_input_end`,
  `agent.tool_input_discarded`, `agent.done_delta`, `agent.step_finish` and `agent.finish` (see
  [Events Reference](AGENTS.md#events-reference)). Observers with a `default` arm ignore them.
- `Usage::$cacheReadTokens`, `$cacheWriteTokens` and `$reasoningTokens`, `null` when not reported.
- `RequestLimits::$tickInterval` (default `0.25`): the cancellation token and run limits are
  checked while a model is silent, on the providers listed under
  [Cancellation and idle ticks](PROVIDERS.md#cancellation-and-idle-ticks).
- `RequestLimits::$maxToolArgumentBytes` (default 4 MiB) and `$maxToolArgumentDepth` (default `64`)
  cap one tool call's arguments on the bundled HTTP providers and `CliProvider`; a call over either
  is dropped like one whose arguments are not a JSON object (see
  [Request Limits](PROVIDERS.md#request-limits)). Raise them with `withRequestLimits()` for a tool
  that really takes more; `CliProvider` gains `withRequestLimits()`, `requestLimits()` and an
  optional `requestLimits:` constructor argument for this, and applies only these two caps.
- `Agent` takes `runLimits`, `cancellationToken` and `providerOptions` (see
  [The ready-made `Agent`](AGENTS.md#the-ready-made-agent)).
