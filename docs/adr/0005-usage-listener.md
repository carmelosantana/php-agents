# 0005. Provider usage listeners

## Context

Hosts need usage, model, vendor IDs, latency, and failure status for each inference request.
Structured output returns the decoded value and leaves request metadata at the provider.
A structured return type that includes usage would cover only that path and require every
caller to collect the metadata. A PSR-14 dispatcher would add a dependency for a single event
type. Both would still require instrumentation at each request site.

## Decision

Hosts opt in through the separate `UsageReporting` interface. `withUsageListener()` returns a
provider copy with the supplied listener. Copies made with `withModel()` or `withRequestLimits()`
retain it. `CompositeUsageListener` lets hosts deliver the same report to several listeners.

Each generation HTTP request or CLI inference run produces one report once it finishes, fails,
or is abandoned. The operation names the public entry point (`chat`, `stream`, or `structured`).
A structured call that sends through chat passes its operation in a reserved per-call option.
The request site removes that option before constructing the payload. This keeps the operation
local to the call when Fibers share a provider.

Latency uses a monotonic clock. A stream's elapsed time includes the consumer's pace between
chunks. Exceptions, abandonment before the terminal event, and an `Error` finish reason mark
the report as failed. The report includes any usage parsed so far and the vendor IDs when available.

The provider catches listener failures and attempts to log a warning with only the exception
class. The inference result or exception is preserved. A composite catches failures in each
child so the remaining listeners still receive the report.

## Scope

Reports omit message content, prompts, response text, schemas, tool arguments, embeddings,
and content hashes. Model listing, availability checks, model pulls and lookups, Gemini image
downloads, MCP traffic, embedding requests, and the deprecated `LlamaCppProvider` are outside
the reporting scope. Pricing and agent-run totals remain the host's and agent's responsibilities,
respectively.
