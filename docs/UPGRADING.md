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
| `AbstractProvider::stream()` is `final`; the agent iterates `streamEvents()` | Subclasses of `AbstractProvider` that override `stream()` | Move the override to `streamEvents()` |
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
  is `'Task was cancelled.'`. A cancel during tools gives every call that did not start a
  `'Cancelled before it ran.'` result with `errorCode` `'cancelled'`. See
  [Cooperative Cancellation](AGENTS.md#cooperative-cancellation).
- `agent.finish` fires once on every exit path that returns. An exception that escapes `run()`
  gets none.

### New and not breaking

- Agent events: `agent.tool_input_start`, `agent.tool_input_delta`, `agent.tool_input_end`,
  `agent.tool_input_discarded`, `agent.done_delta`, `agent.step_finish` and `agent.finish` (see
  [Events Reference](AGENTS.md#events-reference)). Observers with a `default` arm ignore them.
- `Usage::$cacheReadTokens`, `$cacheWriteTokens` and `$reasoningTokens`, `null` when not reported.
- `RequestLimits::$tickInterval` (default `0.25`): the cancellation token and run limits are
  checked while a model is silent, on the providers listed under
  [Cancellation and idle ticks](PROVIDERS.md#cancellation-and-idle-ticks).
- `Agent` takes `runLimits`, `cancellationToken` and `providerOptions` (see
  [The ready-made `Agent`](AGENTS.md#the-ready-made-agent)).
