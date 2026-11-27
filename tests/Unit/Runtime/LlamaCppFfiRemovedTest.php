<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolCallParser;

// 0.20 removes the in-process llama.cpp FFI path (ADR 0006): LlamaServerProvider replaces it.
// LlamaCppToolCallParser stays: alpaca-bot uses it to recover tool calls that models write as text.
// The upgrade guide and the ADRs record the removed names; nothing else in the repo may use them.

const LLAMA_CPP_FFI_REMOVED_CLASSES = [
    'CarmeloSantana\PHPAgents\Provider\LlamaCppProvider',
    'CarmeloSantana\PHPAgents\Runtime\LlamaCpp\FfiLlamaCppNativeApi',
    'CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppJsonGrammar',
    'CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeApiInterface',
    'CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeHandle',
    'CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime',
    'CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppStructuredOutputValidator',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppHistoryFormatter',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppMultimodalContext',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppMultimodalNormalizer',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppProjectorResolver',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppPromptContext',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppStructuredOutputContext',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppStructuredOutputNormalizer',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppTemplateRegistry',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppTemplateResolver',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolPromptInjector',
    'CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolSchemaNormalizer',
];

const LLAMA_CPP_FFI_REMOVED_SCRIPTS = [
    'setup-llama-cpp.sh',
    'test-llama-cpp-runtime.sh',
    'benchmark-llama-cpp-runtime.php',
    'benchmark-llama-cpp-cache.php',
    'compare-llama-cpp-vs-ollama.php',
];

/**
 * @return list<string> repo-relative paths of the files the reference scan reads
 */
function llamaCppFfiScannedFiles(string $root): array
{
    $files = ['README.md', 'AGENTS.md', 'CLAUDE.md', 'composer.json', 'phpstan.neon', 'phpunit.xml', '.gitattributes', '.gitignore'];

    foreach (['src', 'tests', 'scripts', 'docs', 'examples', '.github'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    // Records of what was removed, and the tests that pin them, name the removed paths on purpose.
    return array_values(array_filter(
        $files,
        static fn(string $path): bool => is_file($root . '/' . $path)
            && $path !== 'docs/UPGRADING.md'
            && !str_starts_with($path, 'docs/adr/')
            && $path !== 'tests/Unit/Runtime/LlamaCppFfiRemovedTest.php'
            && $path !== 'tests/Unit/Docs/LlamaCppFfiRemovalDocTest.php',
    ));
}

$root = dirname(__DIR__, 3);

test('the FFI classes are gone', function (string $class) {
    expect(class_exists($class) || interface_exists($class))->toBeFalse();
})->with(LLAMA_CPP_FFI_REMOVED_CLASSES);

test('the FFI scripts are gone', function (string $script) use ($root) {
    expect(file_exists($root . '/scripts/' . $script))->toBeFalse();
})->with(LLAMA_CPP_FFI_REMOVED_SCRIPTS);

test('src/Runtime/LlamaCpp and docs/LOCAL-RUNTIME.md are gone', function () use ($root) {
    expect(is_dir($root . '/src/Runtime/LlamaCpp'))->toBeFalse()
        ->and(file_exists($root . '/docs/LOCAL-RUNTIME.md'))->toBeFalse();
});

test('nothing outside the upgrade guide and the ADRs names a removed class, script or setting', function () use ($root) {
    $needles = [
        ...array_map(static fn(string $class): string => substr($class, (int) strrpos($class, '\\') + 1), LLAMA_CPP_FFI_REMOVED_CLASSES),
        ...array_map(static fn(string $script): string => pathinfo($script, PATHINFO_FILENAME), LLAMA_CPP_FFI_REMOVED_SCRIPTS),
        'Runtime\LlamaCpp',
        'LOCAL-RUNTIME',
        'LLAMA_CPP_LIB_PATH',
        'ext-ffi',
        ':llama-cpp',
    ];
    $hits = [];

    foreach (llamaCppFfiScannedFiles($root) as $path) {
        $text = (string) file_get_contents($root . '/' . $path);

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $hits[] = "{$path}: {$needle}";
            }
        }
    }

    expect($hits)->toBe([]);
});

test('LlamaCppToolCallParser stays, in its namespace and not deprecated', function () {
    expect(class_exists(LlamaCppToolCallParser::class))->toBeTrue()
        ->and((string) (new ReflectionClass(LlamaCppToolCallParser::class))->getDocComment())->not->toContain('@deprecated');
});
