# Upgrading

## Upgrading to 0.19

0.19 is additive and requires no application changes. To receive usage and request metadata
for each inference request, opt in with a [usage listener](PROVIDERS.md#usage-listener).

## Upgrading to 0.18.1

### Behaviour changes

| Change | Who is affected | What to do |
|--------|-----------------|------------|
| `RunLimits::$maxResponseBytes` defaults to `RunLimits::DEFAULT_MAX_RESPONSE_BYTES` (32 MiB), the same as one request's `RequestLimits::DEFAULT_MAX_RESPONSE_BYTES`; it was `0` (no limit). An agent built with no `runLimits`, or with a `RunLimits` that leaves the field out, ends a run that receives more than 32 MiB from the provider in total as `BudgetExhausted` (`Response size limit reached.`). This closes the 0.18 known issue: a hostile endpoint could return many tool rounds, each within the per-request cap, that together exhausted memory | Runs whose provider responses add up to more than 32 MiB, and applications that raised `RequestLimits::$maxResponseBytes` above 32 MiB | Pass a larger `maxResponseBytes`, or `new RunLimits(maxResponseBytes: 0)` to opt out. `maxEstimatedTokens` keeps no default: its estimate re-counts the whole conversation on every iteration, so any fixed default would cut long legitimate runs |
| `RunLimits::$maxResponseBytes` no longer counts an Anthropic response twice: a finish's content-block JSON counts only beyond the thinking, text and tool input the response already streamed | Anthropic runs near a `maxResponseBytes` you set, which 0.18 ended at about half the bytes | None; lower the limit if you sized it for double counting |
| An exception an observer throws while a response streams escapes `run()`, as it does everywhere else; 0.18 caught it and ended the run `Error` with `Provider error: …` and `agent.error` | Hosts whose observers throw (a closed client connection, for example) and that relied on getting an `Output` back | Catch the exception around `run()`, or catch it inside the observer; to stop a run from an observer, use the cancellation token |
| `SseStreamParser` checks the HTTP status on the first chunk that is not a timeout, whether or not the HTTP client marks it first; it checked only a chunk marked first, so a 4xx or 5xx from a client that never sends one was parsed as a stream | Custom `HttpClientInterface` implementations or decorators that do not yield symfony's first chunk | None; such an error answer now throws the Symfony `ClientException` or `ServerException`, as it does on the bundled clients |
| An Anthropic stream whose `tool_use` arguments passed `RequestLimits::$maxToolArgumentBytes` and that ends on a reason other than tool use (`end_turn`, say) gives `Finish::$contentBlocks` `[]`, as a call whose arguments are not a JSON object does; 0.18 kept that call in the blocks with `input: {}` | `streamEvents()` and `stream()` callers that read `contentBlocks` | None; replay falls back to the message without its blocks |
| A Gemini `functionCall` with no name is dropped (logged at debug), in `streamEvents()` and `chat()`, like a call whose arguments are not a JSON object: a finish that would have been `ToolUse` is `Error`. `chat()` used to fail with a `TypeError`, and the stream ended the call with an empty name | Gemini responses with a nameless call | None |
| Once the cancellation token reports a cancel, the run stays cancelled until `run()` returns, even if the token later reports `false`; 0.18 re-read it at each check, so a token that un-cancelled could, for example, answer every tool call as cancelled and then carry on to the next iteration | Tokens whose `isCancelled()` can go back to `false` within a run | None; reset the token between runs as before, since each `run()` reads it afresh |

## Upgrading to 0.18

0.18 streams typed events from every provider (see [Streaming Events](PROVIDERS.md#streaming-events))
and adds a `Cancelled` finish reason. Most applications that only use the bundled providers and
`Agent` need no change; the breaking changes fall on custom providers, direct `stream()` callers,
usage tallies and exhaustive matches on `AgentFinishReason`.

### Breaking changes

| Change | Who is affected | What to do |
|---|---|---|
| `composer.json` requires `ext-mbstring` | Installs on PHP without the mbstring extension: every run now passes finish telemetry through `UntrustedText`, which uses `mb_*` functions, so 0.18 fails there with `Call to undefined function mb_scrub()` | Enable mbstring (it ships with most PHP builds), or require `symfony/polyfill-mbstring` in your own project |
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
  arrived before a cancel, a streaming run limit or a provider exception ended the stream is counted in
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
  result; `agent.warning` still fires once with that text after the response's kept calls run (a
  response that ends the run instead, with `done`, a non-`ToolUse` finish, a cancel or a limit, fires
  none; the discards are then the only signal). A second `done` call in one response now
  counts toward the cap.
- The agent rewrites `agent.tool_input_delta` and `agent.tool_input_end` payloads to the id and name
  the call opened with when the provider's later event names another. An empty start id or name is
  filled from the first delta carrying an id, or from the end, so a Responses-compatible server that
  sends `call_id` and `name` only on the done item still runs the tool under that id. A call that
  opens without a name joins the per-turn cap (and the `done` preview) when its end names it;
  nameless calls past the per-turn slots left plus one (128 with no per-turn cap) are discarded as
  they open with `tool_call_limit`. One response tracks at most
  `AbstractAgent::MAX_TOOL_CALLS_PER_RESPONSE` (= `RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE`,
  1024) calls. The bundled streaming providers stop at the same ceiling and finish `Error` (raw
  reason `tool_call_overflow`), so such a run ends `Error` with no call run; for a custom provider
  the agent's own bound applies: the first call past it gets
  `agent.tool_input_discarded` reason `tool_call_overflow`, and calls past it are neither forwarded
  nor run. A `ToolInputStart` for an index the response already used, or a second `ToolInputEnd`
  for an ended call, is ignored.
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
- New `RequestLimits::$maxToolArgumentBytes` (default 4 MiB) and `$maxToolArgumentDepth` (default
  `64`) cap one tool call's arguments on the bundled HTTP providers and `CliProvider`. A call over
  either is dropped like one whose arguments are not a JSON object, so a 0.17 response with such a
  call, which ran, now finishes `Error` (see [Request Limits](PROVIDERS.md#request-limits)). While
  streaming, a call's argument deltas stop (no more `ToolInputDelta`) once they pass the byte cap,
  and Gemini and `CliProvider` check a whole call before yielding its one delta, so a dropped call
  there gets a `ToolInputStart` and no delta. Raise the caps with `withRequestLimits()` for a tool
  that really takes more; `CliProvider` gains `withRequestLimits()`, `requestLimits()` and an
  optional `requestLimits:` constructor argument for this.
- `ToolArguments::decode()` and `fromDecoded()` apply the default caps (4 MiB, depth 64) when
  called without `RequestLimits`; pass `new RequestLimits(maxToolArgumentBytes: 0, maxToolArgumentDepth: 0)`
  for the 0.17 behaviour. `ToolArguments::toJson()` now writes UTF-8 and slashes unescaped, and
  `null` for arguments it cannot encode, so the `ToolInputDelta` and `stream()` argument strings of
  Gemini, Responses, CLI and llama.cpp can differ byte-for-byte from 0.17 (same JSON value).
- `CliProvider` honours `RequestLimits::$maxResponseBytes` (default 32 MiB) on the process's stdout:
  `streamEvents()` stops reading the process stream past it and `chat()` refuses a larger result,
  each with a `ProviderException` (`response exceeded N bytes`). Pass `maxResponseBytes: 0` for no cap.
  `ProviderFactory` builds a `CliProvider` with the default `RequestLimits`; set other caps with
  `withRequestLimits()` on the provider it returns.
- Image downloads (Gemini image URLs, `RemoteImageResolver`) log a refused URL as scheme, host and
  `/[redacted]` instead of the full URL, and log a failure as the exception class plus that URL
  form instead of the exception message.
- `McpClient::CLIENT_VERSION`, sent as the MCP `clientInfo` version, is `0.18.0` (it still read
  `0.16.1` in 0.17).
- `SseStreamParser` applies its 1 MiB line cap (`maxLineBytes`) to every line, not only to one
  still waiting for its newline: a provider stream with a longer complete line now throws a
  `ProviderException` (`SSE line exceeded … bytes`) instead of decoding it.

### Deprecated

- The in-process llama.cpp FFI path is deprecated in 0.18 and will be removed or fixed in 0.20:
  `LlamaCppProvider`, every class under `Runtime\LlamaCpp`, and the `setup-llama-cpp.sh`,
  `test-llama-cpp-runtime.sh` and llama.cpp benchmark scripts. Move to `LlamaServerProvider`
  ([LlamaServerProvider](PROVIDERS.md#llamaserverprovider)): run `llama-server -m model.gguf --jinja`
  and set a `baseUrl` on the `llama-cpp` provider config, or construct the provider directly.
  - The binding is incompatible with llama.cpp after b9174 (2026-05-16): structs it passes by value
    changed, and a newer build segfaults on the first model load.
  - It does not stream under `Agent`: with tools, which `Agent` always passes, it
    buffers the whole generation, and a cancel, `maxRunSeconds` and `maxResponseBytes` act only
    after generation ends. The buffer has no cap. 0.18 does not fix this.
  - `setup-llama-cpp.sh` now builds llama.cpp at tag b9174 (and warns that `--install-method brew`
    installs a newer, incompatible build).
  - `LlamaCpp\LlamaCppToolCallParser` is not deprecated.

### New and not breaking

- Agent events: `agent.tool_input_start`, `agent.tool_input_delta`, `agent.tool_input_end`,
  `agent.tool_input_discarded`, `agent.done_delta`, `agent.step_finish` and `agent.finish` (see
  [Events Reference](AGENTS.md#events-reference)). Observers with a `default` arm ignore them.
- `Usage::$cacheReadTokens`, `$cacheWriteTokens` and `$reasoningTokens`, `null` when not reported.
- `RequestLimits::$tickInterval` (default `0.25`): the cancellation token and run limits are
  checked while a model is silent, and at least every `tickInterval` while a stream sends data that
  yields no other event (a tool call over the argument cap, keep-alives), on the providers listed
  under [Cancellation and idle ticks](PROVIDERS.md#cancellation-and-idle-ticks). A `streamEvents()`
  consumer sees these ticks between ordinary events on a long stream.
- `Agent` takes `runLimits`, `cancellationToken` and `providerOptions` (see
  [The ready-made `Agent`](AGENTS.md#the-ready-made-agent)).
- `LlamaServerProvider` runs a local GGUF through llama.cpp's `llama-server` (see
  [LlamaServerProvider](PROVIDERS.md#llamaserverprovider)). `ProviderFactory` makes one for
  `llama-cpp/<model>` (or `llamacpp/<model>` or `llama-server/<model>`) when that provider's config has a `baseUrl`; a config
  with a `baseUrl` used to be ignored in favour of the injected runtime. Without a `baseUrl` the
  factory still makes the deprecated `LlamaCppProvider` from the injected runtime.
