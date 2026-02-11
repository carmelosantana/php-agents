# REVIEW.md — php-agents

> Code review performed: 2026-02-10
> Package: `carmelosantana/php-agents`
> PHP: ^8.4 (composer.json) | Guidelines target: ^8.5
> PHPStan: Level 8 — **0 errors** (clean pass)
> Tests: **0 tests** (Pest installed, no test files or phpunit.xml)

---

## Summary

The php-agents library is architecturally sound. All 6 BUILD.md phases are code-complete:

- 11 contracts/interfaces
- 4 backed enums
- 9 value objects (all `final readonly`)
- 6 parameter types (all `final readonly`)
- 5 message types + Conversation
- 3 providers + factory
- 4 toolkits (Filesystem, Web, Shell, Memory)
- 3 bundled agents (File, Web, Code)
- Context window + token counters
- Memory system (FileMemory, InMemoryVectorStore, 2 embedding providers)

Code style is consistent (PER-CS 2.0, `declare(strict_types=1)` everywhere, full type declarations). PHPStan level 8 passes clean. The main gaps are: **zero test coverage**, **no domain exceptions**, **no PHP 8.5 feature usage**, and **missing project files** (README, LICENSE, .gitignore).

---

## Critical Issues

### 1. No test infrastructure — Pest cannot run

**Location:** Project root (missing `phpunit.xml`)
**Impact:** `./vendor/bin/pest` fails with XML parse error. Zero tests exist.

**Fix:** Create `phpunit.xml` at the project root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

And create `tests/Pest.php`:

```php
<?php

declare(strict_types=1);
```

Then write tests for all BUILD.md phases (enums, value objects, parameters, messages, config, tools, providers, agent loop, context window, memory).

### 2. No error handling around provider calls in agent loop

**Location:** `src/Agent/AbstractAgent.php` lines 85–128
**Impact:** HTTP failures from `$this->provider->chat()` or tool exceptions propagate uncaught, crashing the agent loop without cleanup or notification.

**Fix:** Wrap the provider call and tool execution in try/catch blocks:

```php
try {
    $response = $this->provider->chat($conversation->messages(), $allTools);
} catch (\Throwable $e) {
    $this->notify('agent.error', $e->getMessage());
    return new Output(
        content: 'Provider error: ' . $e->getMessage(),
        toolResults: $allToolResults,
        usage: $totalUsage,
        iterations: $i + 1,
    );
}
```

Same pattern for `$tool->execute()` — catch, emit `agent.tool_error`, add error result to conversation, continue loop.

---

## High Severity

### 3. No domain-specific exception classes

**Location:** `src/Agent/AbstractAgent.php:170`, `src/Config/OpenClawConfig.php:23–30`
**Impact:** Violates AGENTS.md: _"Throw specific exceptions — never `throw new \Exception()`. Create domain exceptions."_

**Fix:** Create `src/Exception/` directory with:

```
src/Exception/
├── ToolNotFoundException.php      (extends \RuntimeException)
├── ConfigNotFoundException.php    (extends \RuntimeException)
├── ProviderException.php          (extends \RuntimeException)
└── MaxIterationsException.php     (extends \RuntimeException)
```

Each with a static factory method:

```php
final class ToolNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Unknown tool: %s', $name));
    }
}
```

Replace `throw new \RuntimeException("Unknown tool: {$name}")` in `AbstractAgent::findTool()` with `throw ToolNotFoundException::forName($name)`.

### 4. ContextWindow built but never integrated into the agent loop

**Location:** `src/Context/ContextWindow.php` exists, `src/Agent/AbstractAgent.php` never uses it
**Impact:** Token budget tracking is available but unused — agents have no awareness of context window limits.

**Fix:** Add optional `ContextWindowInterface` to `AbstractAgent` constructor. Record usage after each `chat()` call. Check `hasCapacity()` before next iteration. Emit `context.warning` / `context.critical` events.

### 5. Missing project root files

**Location:** Project root
**Impact:** No README (users can't find installation/usage docs), no LICENSE file (legal ambiguity despite `composer.json` declaring MIT), no `.gitignore` (vendor/ and cache files will be committed).

**Fix:** Create:
- `README.md` — Installation, quick start, API overview, example usage
- `LICENSE` — MIT license text with copyright
- `.gitignore` — `vendor/`, `.env`, `*.cache`, `.phpunit.result.cache`, `.phpunit.cache/`
- `CHANGELOG.md` — Initial v1.0.0 entry

### 6. `psr/log` declared but never used

**Location:** `composer.json` requires `psr/log ^3.0`, no `LoggerInterface` injected anywhere
**Impact:** Unused dependency. More importantly, the library has no logging capability — provider HTTP calls, tool executions, and errors are silent.

**Fix:** Either:
- **(a)** Add optional `?LoggerInterface $logger = null` to `AbstractProvider` and `AbstractAgent` constructors, log HTTP requests/responses, tool executions, and errors.
- **(b)** Remove `psr/log` from `require` if logging isn't wanted for v1 — add it to `suggest` instead.

Option (a) is recommended — it makes debugging agent behavior much easier.

### 7. `OpenAICompatibleProvider` not marked `final`

**Location:** `src/Provider/OpenAICompatibleProvider.php:14`
**Impact:** Violates "final by default" guideline. `OllamaProvider` extends it, creating tight coupling through inheritance.

**Fix:** Make `OpenAICompatibleProvider` `final`. Refactor `OllamaProvider` to use composition:

```php
final class OllamaProvider implements ProviderInterface
{
    private OpenAICompatibleProvider $inner;

    public function __construct(string $model = 'llama3.2', string $baseUrl = 'http://localhost:11434/v1')
    {
        $this->inner = new OpenAICompatibleProvider($model, $baseUrl, 'ollama-local');
    }

    public function chat(array $messages, array $tools = []): Response
    {
        return $this->inner->chat($messages, $tools);
    }
    // ... delegate all ProviderInterface methods, add pull() / hasModel() as Ollama-specific
}
```

### 8. `AnthropicProvider::structured()` is a passthrough

**Location:** `src/Provider/AnthropicProvider.php` — `structured()` just delegates to `chat()`
**Impact:** No actual JSON Schema enforcement for structured output. Callers expecting structured responses may get freeform text.

**Fix:** Add a `@todo` comment documenting the limitation. For a real implementation, inject the JSON Schema into the system prompt and/or use Anthropic's `tool_use` as a structured output mechanism.

---

## Medium Severity

### 9. Public mutable properties on AbstractAgent

**Location:** `src/Agent/AbstractAgent.php:224–225`

```php
public string $lastEvent = '';
public mixed $lastEventData = null;
```

**Impact:** Violates "readonly by default" and encapsulation. Any code can mutate agent event state.

**Fix:** Make private, add getters:

```php
private string $lastEvent = '';
private mixed $lastEventData = null;

public function lastEvent(): string { return $this->lastEvent; }
public function lastEventData(): mixed { return $this->lastEventData; }
```

### 10. `Document::setEmbedding()` mutates state

**Location:** `src/Memory/Document.php`
**Impact:** Violates immutability guidelines. `Document` is `final` but not `readonly` because of this setter.

**Fix:** Replace `setEmbedding(array $embedding): void` with:

```php
public function withEmbedding(array $embedding): self
{
    $clone = clone $this;
    $clone->embedding = $embedding;
    return $clone;
}
```

### 11. `SystemPrompt` mutates instances in static methods

**Location:** `src/Prompt/SystemPrompt.php`
**Impact:** Static `with*()` methods modify the passed instance rather than cloning. The API suggests immutability but the implementation mutates.

**Fix:** Clone before modifying:

```php
public static function withTools(array $tools, self $prompt): self
{
    $new = clone $prompt;
    $new->tools = $tools;
    return $new;
}
```

### 12. `Tool::$maxTries` is unused dead code

**Location:** `src/Tool/Tool.php:20`
**Impact:** Property exists but `execute()` never checks or decrements it.

**Fix:** Either implement retry logic in `execute()` (try up to `$maxTries` on failure before returning error), or remove the property entirely.

### 13. `Conversation::estimateTokens()` duplicates HeuristicCounter

**Location:** `src/Message/Conversation.php:80–88`
**Impact:** Token estimation logic exists in two places. If the heuristic changes, both must be updated.

**Fix:** Delegate to `HeuristicCounter`:

```php
public function estimateTokens(): int
{
    $counter = new HeuristicCounter();
    return $counter->count(json_encode($this->toArray()) ?: '');
}
```

### 14. `EnvConfig::get()` uses falsy coalescing

**Location:** `src/Config/EnvConfig.php:24`

```php
return getenv($key) ?: $default;
```

**Impact:** `getenv()` returning `''` (empty string) or `'0'` falls through to `$default`. An env var set to empty string or zero won't be returned correctly.

**Fix:**

```php
$value = getenv($key);
return $value !== false ? $value : $default;
```

### 15. Embedding providers have no error handling

**Location:** `src/Embedding/OllamaEmbeddingProvider.php`, `src/Embedding/OpenAIEmbeddingProvider.php`
**Impact:** HTTP failures (network errors, 4xx/5xx) propagate as raw Symfony HTTP exceptions.

**Fix:** Wrap API calls in try/catch and throw a `ProviderException` with context:

```php
try {
    $response = $this->client->request('POST', ...);
    return $response->toArray();
} catch (\Throwable $e) {
    throw new ProviderException(
        sprintf('Embedding request failed: %s', $e->getMessage()),
        previous: $e,
    );
}
```

---

## Low Severity / Enhancements

### 16. `ShellToolkit` uses FQCN inside closure

**Location:** `src/Toolkit/ShellToolkit.php:115`
**Impact:** Style inconsistency — uses `\CarmeloSantana\PHPAgents\Enum\ToolResultStatus::Success` instead of a `use` import.

**Fix:** Add `use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;` at the top of the file, use `ToolResultStatus::Success` inside closures.

### 17. `FilesystemToolkit::resolvePath()` jail may fail on non-existent root

**Location:** `src/Toolkit/FilesystemToolkit.php:278`
**Impact:** If `realpath($this->rootPath)` returns `false` (root doesn't exist yet), the jail check falls back to unresolved path, which could  be bypassed with `../` sequences.

**Fix:** Create root directory in constructor if it doesn't exist, or throw early:

```php
$realRoot = realpath($this->rootPath);
if ($realRoot === false) {
    throw new \RuntimeException("Root path does not exist: {$this->rootPath}");
}
```

### 18. `InMemoryVectorStore` uses generic exception

**Location:** `src/VectorStore/InMemoryVectorStore.php:18`
**Impact:** Throws `\InvalidArgumentException` instead of a domain exception.

**Fix:** Create and use `DocumentException::missingEmbedding()`.

### 19. PHP 8.5 features not adopted (deferred enhancement)

**Location:** Throughout codebase
**Impact:** The project description says "PHP 8.5 agent framework" but uses zero PHP 8.5-specific features.

**Fix (when ready):** Adopt incrementally:
- `array_first()` / `array_last()` → `Conversation::first()` / `last()`
- Pipe operator `|>` → `SystemPrompt` builder chain, config resolution
- `FILTER_THROW_ON_FAILURE` → `EnvConfig` validation
- Bump `composer.json` `php` constraint to `^8.5`

### 20. `composer.json` has no `version` field

**Location:** `composer.json`
**Impact:** Composer defaults to `1.0.0`. Git tags should be used for versioning, but a warning is emitted on every command.

**Fix:** Either add `"version": "1.0.0"` or ensure Git tags are used (Packagist extracts version from tags automatically — no field needed if publishing via Git).

---

## Verification Commands

```bash
# Validate composer.json
composer validate

# Static analysis (currently passes clean)
./vendor/bin/phpstan analyse -l 8 src/

# Tests (currently fails — no phpunit.xml)
./vendor/bin/pest

# Check for strict_types compliance
grep -rL 'declare(strict_types=1)' src/
# Expected: no output (all files have it)
```

---

## Issue Summary

| Severity | Count | Key Concerns |
|----------|-------|-------------|
| Critical | 2 | No test infrastructure, no error handling in agent loop |
| High | 6 | No domain exceptions, ContextWindow unused, missing project files, unused psr/log, final/composition violations |
| Medium | 7 | Public mutable state, mutating setters, dead code, duplicated logic |
| Low | 4 | Style inconsistencies, path jail edge case, PHP 8.5 adoption |
| **Total** | **19** | |
