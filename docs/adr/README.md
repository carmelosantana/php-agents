# Architecture decision records

One file per decision that shapes the public API or a cross-cutting behaviour: context, the
decision, and its consequences. Plans, specs and work ledgers do not live in this repo.

| ADR | Decision |
| --- | --- |
| [0001](0001-mcp-client.md) | A hand-rolled, HTTP-only MCP client with fingerprint-pinned tools |
| [0002](0002-structured-output-schemas.md) | Structured-output schemas are normalised per provider, never trusted as sent |
| [0003](0003-provider-request-limits.md) | Provider requests are bounded per request and follow no redirect |
| [0004](0004-streaming-events.md) | Providers stream typed events; stream() is a projection of them |
| [0005](0005-usage-listener.md) | Providers can report usage and metadata for each inference request |
| [0006](0006-remove-llama-cpp-ffi.md) | The in-process llama.cpp FFI path is removed; `LlamaServerProvider` replaces it |
| [0007](0007-structured-streaming.md) | Structured output streams through the opt-in `StructuredStreaming` interface |
