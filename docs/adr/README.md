# Architecture decision records

One file per decision that shapes the public API or a cross-cutting behaviour: context, the
decision, and its consequences. Plans, specs and work ledgers do not live in this repo.

| ADR | Decision |
| --- | --- |
| [0001](0001-mcp-client.md) | A hand-rolled, HTTP-only MCP client with fingerprint-pinned tools |
| [0002](0002-structured-output-schemas.md) | Structured-output schemas are normalised per provider, never trusted as sent |
| [0004](0004-streaming-events.md) | Providers stream typed events; stream() is a projection of them |
