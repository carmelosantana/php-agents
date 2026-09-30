# php-agents 0.16.1: Gemini `structured()` sends `properties` as an object

Kanboard #4556. Branch `fix/gemini-structured-properties` off `origin/main` (b712aa8, the v0.16.0 merge). No spec document; the ticket is the authority.

## Context

`GeminiProvider::structured()` decodes the caller's schema with `json_decode($schema, true)` and hands it to `normalizeSchemaForGemini()` without running `Schema\JsonSchemaRepair::repair()`. A decoded `{}` and a decoded map keyed `"0"`, `"1"`, … are PHP lists, so an empty or numeric-keyed `properties` map goes out as `[]` / `[…]`. The tool path does not have this bug: `SchemaTool::toFunctionSchema()` repairs first.

Repairing turns those maps into `\stdClass`, and `normalizeSchemaForGemini()` does not descend into a `\stdClass` `properties` map, so its members keep a lower-case `type`. That gap is already live on the tool path (the `numeric-keys` fixture goes out with `"type":"string"` members).

### What Gemini's `Schema` accepts (established, not assumed)

From Google's own discovery document, `https://generativelanguage.googleapis.com/$discovery/rest?version=v1beta`, `schemas.Schema.properties`:

- `properties`: `type: object`, `additionalProperties: {$ref: Schema}`: a map of name to Schema. A map held as a `\stdClass` is a valid position, and its members are Schemas, so the walk must normalise them.
- `items`: `$ref: Schema`: one Schema, never a list. The draft-04 tuple form (`items` as a non-empty list of schemas) is not a shape Gemini accepts at all, so descending into the tuple's members would still send an invalid list.
- `anyOf`: array of Schema. `type` is described "Required. Data type."

## Global Constraints

- No new dependencies: `git diff origin/main -- composer.json composer.lock` stays empty.
- `composer test` and `composer analyse` pass after every task.
- TDD: the failing test is written and seen failing before the implementation.
- Comments and docblocks state only what the code does now. No sentence argues from a rejected alternative, and no sentence claims a completeness the code lacks. Every docblock claim touched must be provable by a probe, mutation or grep.
- Commit messages carry no attribution lines.

## Rulings

- Ruling: a draft-04 tuple `items` (a non-empty PHP list) is replaced by `{}` (a `\stdClass`) on the Gemini path, not descended into and not dropped. Gemini's `items` is one Schema, so the list cannot be sent. `{}` is the codebase's existing Gemini convention for "a constraint Gemini cannot express was removed" (`normalizeChildForGemini()`, the `ref-defs` and `one-of` pins), and keeping `items` present leaves the ARRAY node with an element schema. Cost if wrong: the tuple's per-position constraints are lost for Gemini (as `prefixItems` already is), and if Gemini rejected `{}` it would reject the existing `{}` pins too.

## Task 1: the normalising walk descends into a `\stdClass` `properties` map and replaces a tuple `items`

**Files:** `src/Provider/GeminiProvider.php` (`normalizeSchemaForGemini()` and its docblock), `tests/Unit/Provider/RawSchemaCorpusTest.php`, new fixture `tests/Fixtures/mcp-schemas/tuple-items.json`.

1. Add the fixture `tests/Fixtures/mcp-schemas/tuple-items.json`, exactly:

   ```json
   {"type":"object","properties":{"pair":{"type":"array","items":[{"type":"string"},{"type":"integer"}]}}}
   ```

   The existing test `openai responses goes non-strict exactly for the schemas it cannot close` pins one strict flag per fixture and will fail on the new key. Add `'tuple-items' => <value>` in sorted position, where the value is what the current code produces; say in the report what it is and why that provider produces it. Do not change the Responses provider.

2. Add two rows to the dataset of `gemini renders a raw schema as the payload it sends`, each with a one-line comment in the file's style:

   - `numeric-keys`, expected `[{"functionDeclarations":[{"name":"raw_tool","description":"Raw.","parameters":{"type":"OBJECT","properties":{"0":{"type":"STRING"},"1":{"type":"STRING"}}}}]}]`
   - `tuple-items`, expected `[{"functionDeclarations":[{"name":"raw_tool","description":"Raw.","parameters":{"type":"OBJECT","properties":{"pair":{"type":"ARRAY","items":{}}}}}]}]`

3. Add a Gemini-specific oracle to the corpus file, `geminiSchemaDefects(mixed $node, string $path = '$'): array`, walking a `json_decode(..., false)` tree and reporting the path of (a) every key `items` whose value is a JSON array (Gemini's `items` is one Schema), and (b) every key `type` whose value is a string that is not upper-case. Its docblock cites the discovery document as the source. Give it probe rows like `the predicate reports a list planted at any schema position` does: a tuple `items` reported, a lower-case `type` reported, a clean Gemini payload reporting nothing, and a property *named* `type` or `items` (an object value) not reported.

4. Add a test `gemini tool payloads carry nothing Gemini's Schema rejects`, over `rawSchemaFixtures()`, formatting through `rawSchemaFormatters()['gemini']` and asserting `geminiSchemaDefects($decoded) === []`.

5. Run the Gemini rows and the new test; confirm they FAIL for the reasons expected (`numeric-keys`: lower-case members; `tuple-items`: a list at `items`). Record the failing output in the report.

6. Implement in `normalizeSchemaForGemini()`:
   - a `properties` value that is a `\stdClass` has each array member replaced by `normalizeChildForGemini()` of it, and stays a `\stdClass`;
   - an `items` value that is a non-empty list (`array_is_list`) becomes `new \stdClass()`; any other array `items` is normalised as today.

7. Rewrite the docblock of `normalizeSchemaForGemini()` so every sentence is true of the new code: the "two exceptions" paragraph goes, and the paragraph on `JsonSchemaRepair` describes what the walk now does with a `\stdClass` map and with a tuple `items`. Keep the rest only where it is still true.

8. Run `composer test` and `composer analyse`; commit (`fix(gemini): normalise a stdClass properties map and replace a tuple items`).

## Task 2: `structured()` repairs the schema before normalising it

**Files:** `src/Provider/GeminiProvider.php` (`structured()` and its comment), `tests/Unit/Provider/GeminiStructuredSchemaTest.php`, `tests/Unit/Provider/RawSchemaCorpusTest.php`.

1. In `GeminiStructuredSchemaTest.php`, add a capture that keeps the raw request body, and read the schema back as `json_encode(json_decode($body, false)->generationConfig->responseSchema)` so object-versus-list survives. Add a dataset test `structured sends every properties map as a JSON object` with these rows, schema in, expected encoded `responseSchema` out:

   - `{"type":"object","properties":{}}` → `{"type":"OBJECT","properties":{}}`
   - `{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"string"}}}` → `{"type":"OBJECT","properties":{"0":{"type":"STRING"},"1":{"type":"STRING"}}}`
   - `{"type":"object","properties":{"meta":{"type":"object","properties":{}}}}` → `{"type":"OBJECT","properties":{"meta":{"type":"OBJECT","properties":{}}}}`
   - `{"schema":{"type":"object","properties":{}}}` → `{"type":"OBJECT","properties":{}}` (the `schema` envelope `structured()` unwraps)

2. In `RawSchemaCorpusTest.php`, add `gemini structured() sends every raw schema without a list where an object belongs`, over `rawSchemaFixtures()`: call `structured()` with the fixture's JSON through a `MockHttpClient` that captures the body (reply as `GeminiStructuredSchemaTest` does), decode the body with `json_decode(..., false)`, and assert that `generationConfig.responseSchema` is a `stdClass`, that `listsWhereObjectsBelong()` of it is `[]`, and that `geminiSchemaDefects()` of it is `[]`.

3. Run the new tests; confirm they FAIL (`properties` encoded as a list) and record the output.

4. In `structured()`, pass the extracted `$responseSchema` through `JsonSchemaRepair::repair()` before `normalizeSchemaForGemini()`. Update the comment above the call so it states what the path does now, including the repair, without arguing from what it used to do.

5. Run `composer test` and `composer analyse`; commit (`fix(gemini): repair the structured() schema so a properties map stays an object`).

## Task 3: version 0.16.1

1. Set `McpClient::CLIENT_VERSION` to `'0.16.1'` in `src/Mcp/McpClient.php`.
2. Run `composer test` and `composer analyse`; commit (`chore: 0.16.1`).
