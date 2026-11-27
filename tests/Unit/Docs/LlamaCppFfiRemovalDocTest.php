<?php

declare(strict_types=1);

// 0.20 removes the in-process llama.cpp FFI path. The upgrade guide lists every removal with its
// migration to LlamaServerProvider, the 0.18 deprecation points at it, and ADR 0006 says why.

function llamaCppFfiUpgradeSection(): string
{
    $upgrading = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');
    $start = (int) strpos($upgrading, "## Upgrading to 0.20.0\n");
    $end = strpos($upgrading, "\n## Upgrading to ", $start + 1);

    return substr($upgrading, $start, $end === false ? null : $end - $start);
}

test('LlamaCppFfiRemovalDoc the upgrade guide has a 0.20.0 section', function () {
    $upgrading = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');

    expect($upgrading)->toMatch('/^## Upgrading to 0\\.20\\.0$/m');
});

test('LlamaCppFfiRemovalDoc the 0.20.0 section has a breaking-change row for each removal', function (string $removed) {
    $rows = array_filter(
        explode("\n", llamaCppFfiUpgradeSection()),
        static fn(string $line): bool => str_starts_with($line, '| ') && str_contains($line, $removed),
    );

    expect($rows)->not->toBe([]);
})->with([
    '`LlamaCppProvider`',
    '`Runtime\LlamaCpp`',
    '`LlamaCppHistoryFormatter`',
    '`LlamaCppMultimodalNormalizer`',
    '`LlamaCppToolSchemaNormalizer`',
    'without a `baseUrl`',
    '`scripts/setup-llama-cpp.sh`',
    '`scripts/test-llama-cpp-runtime.sh`',
    '`scripts/benchmark-llama-cpp-runtime.php`',
    '`scripts/benchmark-llama-cpp-cache.php`',
    '`scripts/compare-llama-cpp-vs-ollama.php`',
    '`docs/LOCAL-RUNTIME.md`',
]);

test('LlamaCppFfiRemovalDoc the 0.20.0 section shows the migration to LlamaServerProvider and keeps the parser', function () {
    $section = llamaCppFfiUpgradeSection();

    expect($section)->toContain('### Breaking changes')
        ->toContain('llama-server -m model.gguf --jinja')
        ->toContain('"llama-cpp": { "baseUrl": "http://localhost:8080/v1" }')
        ->toContain('new LlamaServerProvider(')
        ->toContain('`LlamaCpp\LlamaCppToolCallParser` stays')
        ->toContain('0006-remove-llama-cpp-ffi.md');
});

test('LlamaCppFfiRemovalDoc the 0.18 deprecation entry points to the 0.20.0 section', function () {
    $upgrading = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');
    $deprecated = substr($upgrading, (int) strpos($upgrading, "### Deprecated\n"));
    $deprecated = substr($deprecated, 0, (int) strpos($deprecated, "\n### ", 1));

    expect($deprecated)->toContain('[Upgrading to 0.20.0](#upgrading-to-0200)')
        ->not->toContain('removed or fixed in 0.20');
});

test('LlamaCppFfiRemovalDoc ADR 0006 records the removal and why', function () {
    $adr = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/adr/0006-remove-llama-cpp-ffi.md');

    expect($adr)->toContain('b9174')
        ->toContain('segfault')
        ->toContain('no cap')
        ->toContain('LlamaServerProvider')
        ->toContain('LlamaCppToolCallParser')
        ->and(file_get_contents(dirname(__DIR__, 3) . '/docs/adr/README.md'))->toContain('0006-remove-llama-cpp-ffi.md');
});
