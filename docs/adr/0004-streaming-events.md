# 0004: Providers stream typed events; stream() is a projection of them

- Status: accepted (v0.18.0, 2026-11)

## Context

A chat UI that streams replies as server-sent fragments (in the style of the Vercel AI SDK's UI
parts) needs three things the 0.17 streaming API cannot give it:

1. **Stop mid-stream.** A Stop click has to cut a reply off within about a quarter of a second,
   keep the text already shown, and end the run with a finish reason that says "cancelled". In
   0.17 cancellation is only checked when a chunk arrives, so a silent model cannot be stopped,
   and a mid-stream cancel returns `Stop`.
2. **Streamed tool calls.** Tool cards should fill in while the model is still writing the
   arguments (input start, delta, available). `stream()` only hands over finished tool calls.
3. **Live usage.** A context-budget gauge should update after every model response, with cache
   and reasoning tokens counted the same way on every provider.

A `done`-tool answer should also type out live like plain text, and the convenience `Agent`
should accept `RunLimits`, a cancellation token and provider options.

Every SDK revised in the last 18 months (Vercel AI SDK v5, Prism PHP 0.92, symfony/ai 0.7) moved
from one chunk object to typed start/delta/end events, each in a breaking release. Starting on
typed events now avoids paying for that migration later, so 0.18.0 is a breaking release.

Alternatives considered, one line each:

- Optional fields on `Response` chunks: ticks would leak into direct `stream()` callers, and the
  ecosystem has moved away from that shape.
- `streamEvents()` on a separate optional interface: decorating providers would silently hide it,
  so agents behind them would lose the new events without any error.
- Closures in `$options`: options are merged into request payloads and would need stripping at
  every merge point.

## Decision

### Stream events

`Provider\Stream\StreamEvent` is a marker interface; every event is a `final readonly` class.

| Event | Fields | When |
| --- | --- | --- |
| `ReasoningDelta` | `string $delta` | each reasoning/thinking fragment |
| `TextDelta` | `string $delta` | each text fragment |
| `ToolInputStart` | `int $index, string $id, string $name` | a tool call opens |
| `ToolInputDelta` | `int $index, string $id, string $delta` | a raw argument-JSON fragment |
| `ToolInputEnd` | `int $index, string $id, string $name, array $arguments, array $metadata` | arguments complete and decoded to a JSON object |
| `Tick` | none | the stream has been idle for `tickInterval` |
| `Finish` | `ProviderFinishReason $reason, string $rawReason, ?Usage $usage, string $model, array $contentBlocks` | exactly once, last, for every stream that does not throw |

- `$index` is the call's 0-based position in this response, in the order calls opened; `$id` is
  the provider's call id, repeated on every event even where the provider sends it only once.
- Providers that deliver a call whole (Gemini, Ollama, the llama.cpp tool parser) emit
  `ToolInputStart`, one `ToolInputDelta` carrying the full JSON-encoded arguments, then
  `ToolInputEnd`, so every call that ends has at least one delta. Gemini and `CliProvider` check
  the arguments first: a call they drop gets its `ToolInputStart` and no delta.
- Empty or whitespace-only arguments, and an empty object, decode as `{}`. Any other arguments
  that do not decode to a JSON object get no `ToolInputEnd` and the call is dropped, on every
  provider; on the HTTP providers and `CliProvider` so do arguments over `RequestLimits::$maxToolArgumentBytes` or
  `$maxToolArgumentDepth`, which are checked before the single decode. The streaming HTTP
  providers count each call's argument bytes as fragments arrive and, once a call passes the byte
  cap, stop keeping and yielding its deltas, drain the rest of the response and drop the call at
  its end, so an oversize call is never buffered whole. A response that would
  have finished with `ToolUse` then finishes with `Error`; any other reason (`MaxTokens`, for
  example) is kept. `chat()` applies the same rule.
- `ToolInputEnd` is emitted as soon as each call's arguments are complete. The tool calls are the
  `ToolInputEnd` events; the agent runs them only when `Finish->reason` is `ToolUse`.
- Per-call metadata such as Gemini's `thoughtSignature` rides on `ToolInputEnd->metadata`, with
  the values `ToolCall::metadata` holds.
- Errors are thrown as before (`ProviderException`, symfony HTTP exceptions); there is no error
  event.

### Provider contract

- `ProviderInterface` gains
  `streamEvents(array $messages, array $tools = [], array $options = []): iterable<StreamEvent>`.
  `stream()` stays on the interface and is not deprecated.
- `AbstractProvider::stream()` is `final` and projects `streamEvents()` into one shape for every
  provider: one `Response` per text or reasoning delta (finish reason `Stop`, no usage, no tool
  calls), then exactly one terminal `Response` with the finish reason, usage, model, tool calls
  (from `ToolInputEnd`, only on `ToolUse`) and content blocks. Ticks and tool-input events are
  not projected. A subclass that still overrides `stream()` fails loudly.
- Request preparation that Mistral and Ollama did in `stream()` moves to `streamEvents()` (or a
  shared protected hook), so both paths get it.
- Classes that implement `ProviderInterface` directly use the trait
  `Provider\Stream\StreamsEventsFromResponses`, which builds events from their `stream()`:
  text and reasoning deltas, whole-call tool events from the terminal chunk's tool calls, and
  `Finish` from the terminal chunk. Decorators forward with
  `yield from $inner->streamEvents(...)`.

### Idle ticks and polling

- `RequestLimits` gains a trailing `float $tickInterval = 0.25`; 0 disables ticks.
- `SseStreamParser` polls with `$httpClient->stream($response, $tickInterval)` in a loop,
  checking `isTimeout()` before reading content, and re-enters `stream()` after each timeout
  chunk. Polling also covers the wait for headers; the status check happens on the first
  non-timeout chunk.
- While data flows, the parser also yields a `Tick` whenever `tickInterval` has passed since the
  last one (checked before each payload and after each chunk). A provider can turn many payloads
  into no event (fragments of a call over the argument cap, pings, signature deltas), and these
  ticks keep the agent's cancellation and run-time checks running through such traffic.
- The parser tracks the time since the last data and throws the same idle-timeout error symfony
  would once `idleTimeout` passes; `maxDuration` still works through `max_duration`. A tick never
  extends either deadline.
- The parser yields a sentinel per tick, which providers turn into a `Tick` event. When the
  consumer stops iterating, the provider's generator cancels the HTTP response in a `finally`
  block, as it does when the stream throws anything but an HTTP status error (whose response stays
  open for its error body), so a parser limit or provider error event does not rely on the client
  cleaning up a destroyed response.
- Ticks depend on the client honouring the timeout argument of `stream()`. A client that ignores
  it gets no ticks, and cancellation then works per data chunk only. `LlamaCppProvider` and
  `CliProvider` have no SSE stream: they honour cancellation per chunk and emit no ticks.
  `docs/PROVIDERS.md` lists, per provider, whether cancellation works during silence or per
  chunk.

### Agent events

The agent iterates `streamEvents()`. On every event it runs the tick callback, the cancellation
check and the run-limit checks (bytes, time, tokens). `TextDelta` and `ReasoningDelta` keep firing
`agent.text_delta` and `agent.reasoning` with a string. Existing events keep their names,
payloads and order. New events:

| Event | Payload | Fired |
| --- | --- | --- |
| `agent.tool_input_start` | `ToolInputStart` | the model opens a call |
| `agent.tool_input_delta` | `ToolInputDelta` | each argument fragment |
| `agent.tool_input_end` | `ToolInputEnd` | arguments complete, before `agent.tool_call` |
| `agent.done_delta` | `DonePreview{callId, delta}` | provisional decoded text of the `done` call's `response` argument |
| `agent.tool_input_discarded` | `ToolInputDiscarded{index, id, name, reason}` | a call that got `agent.tool_input_start` (or, with no start, `agent.tool_input_end`) will not run |
| `agent.step_finish` | `StepFinish{int iteration, ProviderFinishReason reason, string rawReason, ?Usage usage, string model}` | after each provider response |
| `agent.finish` | `RunFinish{AgentFinishReason reason, Usage usage, int iterations, string model}` | exactly once, on every exit path, just before `run()` returns |

`StepFinish::$rawReason`, `StepFinish::$model` and `RunFinish::$model` are provider-controlled, so the
agent passes them through `UntrustedText::forDisplay()` before notifying: credential shapes are
redacted, control and bidi characters become spaces, whitespace is collapsed and trimmed, and the
text is cut to 128 characters plus a `…` (129 at most). `Finish` on the provider stream stays verbatim.

- `agent.tool_input_discarded` carries one of these reasons, and a UI uses it to retract a card or
  a preview:
  - `not_tool_use`: the response finished with a reason other than `ToolUse`, or with `Error` after
    the call's arguments completed;
  - `invalid_arguments`: the response finished with `Error` and the call's arguments never
    completed as a JSON object;
  - `run_limit`: a run limit (bytes, time, tokens) ended the run first, while streaming or after the
    response;
  - `provider_error`: the stream threw, or ended without a `Finish`, after the call started;
  - `cancelled`: a cancel abandoned the response before it finished;
  - `done_finished_run`: a `done` call earlier in the same response finished the run;
  - `denied`, `invalid_done`: the policy denied the response's first `done` call, or its arguments
    failed validation, and the run continues;
  - `duplicate_done`: a `done` call that was not the first in its response;
  - `tool_call_limit`: the call opened past `RunLimits::$maxToolCallsPerTurn`. The per-turn cap is
    applied while the response streams, so such a call is discarded right after its
    `agent.tool_input_start` (a call whose end came without a start is discarded in place of its
    `agent.tool_input_end`, with no start event), its later input events are not forwarded, and it is never retained
    (its `tool_use` content block included), shown to the policy or answered with a tool result.
    The first `done` call is outside the cap.
  - `tool_call_overflow`: the response opened more than `AbstractAgent::MAX_TOOL_CALLS_PER_RESPONSE`
    (1024) calls, counting kept, waiting and capped ones. Fired once, for the first call past the
    bound, in place of its start (or end); the agent keeps no state for, and forwards no event of,
    any call past it, so a hostile stream cannot grow its bookkeeping. A nameless start that
    repeats a known index is ignored, and a nameless call discarded under the pending bound is not
    kept in the call order.
- **Call identity.** A call keeps the id and name it opened with; the agent rewrites a delta or end
  that names another to them. An empty start id or name is unknown, not a disagreement: the first
  delta carrying an id, or the end, fills it, and the filled value is kept from then on. A call that
  opens without a name is held out of the per-turn cap and the `done` preview until its end names
  it; it is then admitted when its end arrives, after the calls already admitted, and a `done`
  previews its whole answer at once. Calls waiting for a name are bounded as they open: at most the
  ordinary slots left plus one for a possible first `done` (or `AbstractAgent::MAX_PENDING_NAMELESS_CALLS`,
  128, with no per-turn cap). A nameless start past that is discarded at once with
  `tool_call_limit`, and its later events are ignored.
  End or delta id and name bytes that differ from the start's, fill-ins included, count toward
  `maxResponseBytes`. (The Responses API can send a call's `call_id` and `name` only on its
  `output_item.done`.)
- `agent.finish` fires from one place: `run()` wraps the loop and notifies with the `Output` it
  is about to return.
- **`done` previews.** `DonePreviewReader` reads the `done` call's argument JSON incrementally and
  emits the decoded text of the top-level `response` string as it arrives, handling escapes
  (`\n`, `\"`, `\\`, `\uXXXX` with surrogate pairs) split across fragments. It ignores other keys
  and never emits text from a nested object. Only the first `done` call in a response is
  previewed, one preview per call id: the first `done` call to open, while the call that finishes
  the run is the first in completion order (they differ only when `done` calls interleave).
  Acceptance is the existing `agent.done` event, which carries the authoritative `response`; a
  preview that does not finish the run gets `agent.tool_input_discarded`. A `done` call whose
  start has a name but no id holds its preview text until a delta or its end brings the id and
  then releases it under that id, so a preview never carries an id its end or discard does not. Whole-call providers
  deliver the preview in one `agent.done_delta`.
- **`Agent` constructor.** Trailing optional `?RunLimits $runLimits = null`,
  `?CancellationTokenInterface $cancellationToken = null` and `array $providerOptions = []`,
  passed through to `AbstractAgent`.

Every exit path that returns ends with the same tail: `agent.step_finish` when a provider response
completed, then the `agent.done`, `agent.error` and `agent.warning` events that path emitted in
0.17 (none, one, or `warning` before `done` for the reasoning fallback), then `agent.finish`.
Discards fire before `agent.step_finish` when the finish reason rules a call out, and after it
when the run ends for another reason. An exception that escapes `run()` gets no `agent.finish`.

| Exit path | Sequence | `AgentFinishReason` |
| --- | --- | --- |
| Text answer | `step_finish` → `done` → `finish` | `Stop` |
| Reasoning-only answer | `step_finish` → `empty_response` → `warning` → `done` → `finish` | `Stop` |
| `done` tool | `step_finish` → `tool_input_discarded` (the response's other calls) → `done` → `finish` | `Done` |
| Tool-call limit per run | `step_finish` → … tools … → `warning` → `finish` | `BudgetExhausted` |
| Budget wrap-up exhausted | `warning` → `finish` | `BudgetExhausted` |
| Run limit while streaming (bytes, time, tokens; no `step_finish`, even when it trips on the `Finish`, whose usage is still kept) | `tool_input_discarded` (`run_limit`, each started or end-only call) → `error` → `finish` | `BudgetExhausted` |
| Token limit after a response | `step_finish` → `tool_input_discarded` (`run_limit`, each completed call) → `error` → `finish` | `BudgetExhausted` |
| Run limit elsewhere (time at the top of an iteration or during tools, input size) | `error` → `finish` | `BudgetExhausted` |
| Repeated empty responses | `step_finish` → `empty_response` → `error` → `finish` | `EmptyResponse` |
| Max iterations | `error` → `finish` | `MaxIterations` |
| Refusal, content filter, context window | `step_finish` → `finish` | `Refusal`, `ContentFilter`, `ContextWindowExceeded` |
| Provider error finish (`Error`, `PauseTurn`, bare `MaxTokens`) | `step_finish` → `error` → `finish` | `Error` |
| Stream ended without a `Finish` | `tool_input_discarded` (`provider_error`, each started or end-only call) → `error` → `finish` | `Error` |
| Provider exception (token not set; usage of a `Finish` that already arrived is kept) | `tool_input_discarded` (`provider_error`, each started or end-only call) → `error` → `finish` | `Error` |
| Tool `TerminationException` | `step_finish` → `finish` (no `agent.error`) | `Error` |
| Cancel mid-stream (or a provider exception once the token is set; no `step_finish`, even when it lands on the `Finish`, whose usage is still kept) | `tool_input_discarded` (`cancelled`, each started or end-only call) → `error` `'Task cancelled'` → `finish` | `Cancelled` |
| Cancel after a text or `done` answer finished, before it is accepted | `step_finish` → (`budget_warning`) → `tool_input_discarded` (`cancelled`, each call of a `done` response) → `error` `'Task cancelled'` → `finish` | `Cancelled` |
| Cancel after an empty response, landing anywhere from `step_finish` to `empty_response` (`budget_warning` included), before the empty-response policy retries, falls back to reasoning or gives up | `step_finish` → (`budget_warning`) → `empty_response` → `error` `'Task cancelled'` → `finish` | `Cancelled` |
| Cancel at top of iteration or during tools | (`step_finish` already fired for a completed response; no call is open, so no `tool_input_discarded`) → `error` `'Task cancelled'` → `finish` | `Cancelled` |

Tests pin each sequence.

### Cancellation

- New case `AgentFinishReason::Cancelled = 'cancelled'`.
- Checked on every stream event (ticks included), at the top of each iteration, after the
  response finishes (after `agent.step_finish`, after the policy allows `done`, and just before a
  text answer is accepted, and after `agent.empty_response`), before each tool call and before a
  batch starts. A `Finish` that arrived before a cancel, a streaming run limit or a provider
  exception ended the stream has its usage counted and reported to the context window; the run
  still ends without `agent.step_finish` on those paths.
- **Mid-stream.** Iteration stops and the HTTP response is cancelled. `Output->content` is the
  text streamed so far in this step, then a blank line (`"\n\n"`) and the provisional `done`
  preview when both are present, or `'Task was cancelled.'` when both are empty. The preview is
  used as it streamed, even for a call the execution policy would have denied, since the policy
  only sees completed calls. Non-empty text is appended to the returned conversation as an
  `AssistantMessage` with no tool calls. Calls still streaming are dropped,
  and every open call, a `done` preview included, gets `agent.tool_input_discarded` with reason
  `cancelled`.
  A provider that throws, or whose stream ends without a `Finish`, once the token is set (a
  cancel that aborts the transport) ends the same way, not as a provider error.
- **After the response finished.** A cancel that lands before a final answer (text under a
  `Stop`, `ToolUse` or `MaxTokens` finish, a response with a `done` call, or an empty response
  before its policy retries, falls back or gives up) is accepted ends as a mid-stream cancel
  does, with the full streamed text and the `done` preview as it streamed (not the completed
  call's arguments); each call of the response is discarded with `cancelled` and no `agent.done`
  fires. A response of ordinary tool calls goes on to the during-tools rule. Refusal,
  content-filter, context-window and provider-error finishes keep their own finish reason: the
  run ends anyway and the caller needs the reason.
- **During tools.** The assistant's tool-call message is already in the conversation. Finished
  calls keep their results; every call that did not start gets
  `ToolResult::error('Cancelled before it ran.')` with error code `cancelled`, so
  `repairToolPairing()` keeps the group. A running batch is not interrupted; its results are kept,
  then the run ends.
- **Top of iteration.** Returns `Cancelled`.
- `agent.error` with `'Task cancelled'` still fires on every cancel, before `agent.finish`, so
  observers that know only the 0.17 events still see it.

### Usage

- `Usage` gains trailing `?int $cacheReadTokens = null`, `?int $cacheWriteTokens = null` and
  `?int $reasoningTokens = null`. `null` means not reported; `0` means reported zero.
- `promptTokens` counts all input tokens, cache reads and writes included. `completionTokens`
  counts all output tokens, reasoning included. `totalTokens` is the provider's total when given,
  else prompt plus completion.
- One usage parser per provider serves `chat()` and streaming, so both report the same figures.
- Sources: Anthropic cache read/write (reasoning stays `null`); OpenAI Chat
  `prompt_tokens_details.cached_tokens` and `completion_tokens_details.reasoning_tokens`;
  Responses `input_tokens_details.cached_tokens` and `output_tokens_details.reasoning_tokens`;
  Gemini `cachedContentTokenCount` and `thoughtsTokenCount`; Ollama, Mistral, xAI, llama.cpp and
  CLI whatever they report, else `null`.
- `Output->usage` sums every field across steps; a field stays `null` only when every step
  reported `null`.

## Consequences

Breaking changes, hence 0.18.0:

| Change | Who is affected | Migration |
| --- | --- | --- |
| `ProviderInterface::streamEvents()` added | every class implementing the interface directly: provider decorators, custom providers, test doubles | `use StreamsEventsFromResponses;`, or forward with `yield from $inner->streamEvents()` |
| `AgentFinishReason::Cancelled`; cancel no longer returns `Error` | exhaustive `match` on the enum; code treating cancel as an error | add the case; `agent.error` `'Task cancelled'` still fires |
| `stream()` normalised: deltas, then exactly one terminal chunk with finish reason, usage and tool calls | direct `stream()` callers on OpenAI-compatible, Gemini and Responses providers | read the finish reason and usage from the last chunk; summing usage across chunks still works |
| Anthropic `promptTokens` includes cache tokens | cost and usage tallies | read `cacheReadTokens` and `cacheWriteTokens` for the old figure |
| Agent iterates `streamEvents()`; `AbstractProvider::stream()` is `final` | subclasses overriding `stream()` | move the override to `streamEvents()` |
| Unparseable tool arguments drop the call on every provider, in `stream()` and `chat()`, and a `ToolUse` finish becomes `Error` (empty → `{}`) | callers relying on the `[]` fallback | none for valid models; truncated calls no longer run |
| Gemini `completionTokens` includes thought tokens; Responses `totalTokens` is the provider's total; `chat()` usage normalised the same way | usage tallies | read the new fields for the old split |

Behaviour changes that ship with these:

- `stream()` delta chunks carry no model; the model is on the terminal chunk.
- llama.cpp `chat()` reports `ToolUse` where a trailing usage-only chunk used to overwrite it with
  `Stop`, and a llama.cpp stream that ends without a finish reason finishes `Stop`.
- A provider stream that ends with no `Finish` is a provider error (`AgentFinishReason::Error`).
- A cancel at the top of an iteration returns `Cancelled` (0.17: `Error`); a mid-stream cancel
  returns `Cancelled` with the partial text (0.17: `Stop`).

Not breaking: the new observer events, the new `Usage` fields (trailing, optional),
`RequestLimits::$tickInterval` and the new `Agent` parameters.

Known limits:

- An injected client that ignores the timeout argument of `stream()` gets no ticks; a silent
  model can then only be cancelled when the next chunk arrives.
- A tool that is already running is not interrupted by a cancel.
- Text and reasoning block ids, citations and cost are not events yet; they can be added later as
  new event types without breaking anything.
