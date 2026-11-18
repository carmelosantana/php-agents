# 0002: Structured-output schemas are normalised per provider, never trusted as sent

- Status: accepted (v0.15.1 to v0.16.1, 2026-08 to 2026-09)

## Context

Every provider accepts a different JSON Schema dialect. Sending a caller's schema as is failed
silently: OpenAI ignored a bare schema that was not wrapped as
`response_format.json_schema = {name, schema, strict}` and returned prose (fixed in v0.15.1);
strict mode rejects open objects; Gemini's `Schema` has no `additionalProperties`, `$ref`,
`oneOf` or tuple `items`, and a PHP-decoded `{}` becomes a list `[]` (fixed in v0.16.1).

## Decision

- `structured()` and tool schemas go through a per-provider normaliser before sending.
- OpenAI-compatible and Responses: wrap the schema, then use strict mode only when
  `StrictSchemaNormalizer::qualifies()` proves it can close every object without changing what
  the schema accepts; otherwise send it intact with `strict: false`.
- Gemini, Ollama and llama.cpp: run `Schema\JsonSchemaRepair::repair()` first (maps stay
  objects), then walk every subschema position. A constraint the target cannot express is
  replaced by `{}`, never dropped in a way that changes the node's type.
- A cross-provider raw-schema corpus (`tests/Fixtures/mcp-schemas`) pins the output of every
  normaliser, and spec-sourced tests pin the walk/rewrite invariant.

## Consequences

- Callers can pass any reasonable JSON Schema; a schema the target cannot enforce degrades to
  best-effort JSON instead of an API error.
- `ProviderInterface::structured()` still returns a decoded array from some providers and a
  `Response` from others; `Provider\StructuredOutput::extract()` gives callers one array-returning
  shape without changing the interface.
