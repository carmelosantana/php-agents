# 0006. Remove the in-process llama.cpp FFI path

## Context

`LlamaCppProvider` ran a GGUF model inside the PHP process through `ext-ffi` and the classes under
`Runtime\LlamaCpp`. 0.18 deprecated it, with removal or a fix due in 0.20, for three reasons:

- The binding mirrors `llama.h` structs that it passes by value. llama.cpp changed them after
  b9174 (2026-05-16), so on any newer build the first model load segfaults. Keeping it working
  means tracking llama.cpp's C ABI release by release, with a crash, not an error, when it drifts.
- With tools, which `Agent` always passes, it buffered the whole generation before yielding
  anything. A cancel, `maxRunSeconds` and `maxResponseBytes` took effect only after generation
  ended, and the buffer had no cap, so one long or runaway generation could exhaust memory.
- `LlamaServerProvider` (0.18) runs the same models through llama.cpp's `llama-server` over its
  OpenAI-compatible API. It streams under `Agent`, sits behind the same `RequestLimits`, run
  limits and cancellation as the other HTTP providers, and depends on llama.cpp's HTTP API instead
  of its C structs.

## Decision

Remove the FFI path in 0.20.0; do not fix it. That covers `LlamaCppProvider`, every class under
`Runtime\LlamaCpp`, the `Provider\LlamaCpp` helpers that only `LlamaCppProvider` used, the
setup, test and benchmark scripts, and the native runtime guide.

`ProviderFactory` keeps the `llama-cpp` provider key (and its `llamacpp` and `llama-server`
aliases): a config with a `baseUrl` makes a `LlamaServerProvider`. Without a `baseUrl` it throws an
`InvalidArgumentException` that names `LlamaServerProvider`, instead of falling back to a provider
that no longer exists. The factory's `$localModelRuntime` parameter stays, ignored, so positional
callers that pass a CLI runtime after it keep working.

`LlamaCpp\LlamaCppToolCallParser` stays in its namespace with the same API: it parses tool calls a
model writes as JSON text, does not touch FFI, and alpaca-bot depends on it.
`LocalModelRuntimeInterface`, `LocalModelHandleInterface` and the `Runtime\Runtime*` value objects
stay as well, since they are public contracts that hosts may implement.

## Consequences

- Applications that used `LlamaCppProvider` or a `llama-cpp` model without a `baseUrl` must run
  `llama-server` and point `LlamaServerProvider` (or the `llama-cpp` config's `baseUrl`) at it.
  `docs/UPGRADING.md` lists each removal and the migration.
- The library no longer loads native code, and nothing in it needs `ext-ffi` or a llama.cpp build.
- The FFI path's open defects (a forced `Stop` after runtime tool calls, list-shaped parser
  arguments reaching `ToolInputEnd`, no test for an abandoned stream) go away with the code.
- Local inference is one process hop away from PHP. A real-model check of `LlamaServerProvider`'s
  tool calls is run before release.
