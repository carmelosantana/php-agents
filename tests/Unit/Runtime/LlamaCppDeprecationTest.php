<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolCallParser;
use CarmeloSantana\PHPAgents\Provider\LlamaCppProvider;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\FfiLlamaCppNativeApi;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppJsonGrammar;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeApiInterface;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeHandle;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppStructuredOutputValidator;

// 0.18 deprecates the in-process FFI path (it segfaults on llama.cpp after b9174 and does not
// stream under Agent) in favour of LlamaServerProvider. LlamaCppToolCallParser stays: alpaca-bot
// uses it to recover tool calls that models write as text.

$root = dirname(__DIR__, 3);

test('the FFI provider and every Runtime\\LlamaCpp class are deprecated in favour of LlamaServerProvider', function (string $class) {
    $doc = (string) (new ReflectionClass($class))->getDocComment();

    expect($doc)->toMatch('/@deprecated 0\.18\b[^\n]*LlamaServerProvider/');
})->with([
    LlamaCppProvider::class,
    FfiLlamaCppNativeApi::class,
    LlamaCppJsonGrammar::class,
    LlamaCppNativeApiInterface::class,
    LlamaCppNativeHandle::class,
    LlamaCppNativeRuntime::class,
    LlamaCppStructuredOutputValidator::class,
]);

test('every class under src/Runtime/LlamaCpp is in the deprecated list', function () use ($root) {
    $files = array_map(fn(string $f): string => basename($f, '.php'), glob($root . '/src/Runtime/LlamaCpp/*.php') ?: []);
    sort($files);

    expect($files)->toBe([
        'FfiLlamaCppNativeApi',
        'LlamaCppJsonGrammar',
        'LlamaCppNativeApiInterface',
        'LlamaCppNativeHandle',
        'LlamaCppNativeRuntime',
        'LlamaCppStructuredOutputValidator',
    ]);
});

test('LlamaCppToolCallParser is not deprecated', function () {
    expect((string) (new ReflectionClass(LlamaCppToolCallParser::class))->getDocComment())->not->toContain('@deprecated');
});

test('the llama.cpp setup and test scripts say they are deprecated', function (string $script) use ($root) {
    expect((string) file_get_contents($root . '/scripts/' . $script))->toContain('Deprecated in 0.18')
        ->toContain('LlamaServerProvider');
})->with(['setup-llama-cpp.sh', 'test-llama-cpp-runtime.sh', 'benchmark-llama-cpp-runtime.php', 'benchmark-llama-cpp-cache.php', 'compare-llama-cpp-vs-ollama.php']);

test('the docs record the FFI deprecation, its b9174 limit and the Agent buffering', function (string $path) use ($root) {
    $doc = (string) file_get_contents($root . '/' . $path);

    expect(strtolower($doc))->toContain('deprecated in 0.18')
        ->and($doc)->toContain('b9174')
        ->toContain('buffers the whole generation')
        ->toContain('LlamaServerProvider')
        ->toContain('0.19');
})->with(['docs/PROVIDERS.md', 'docs/UPGRADING.md', 'docs/LOCAL-RUNTIME.md']);

test('setup-llama-cpp.sh builds llama.cpp at b9174, the last tag the FFI binding works with', function () use ($root) {
    $script = (string) file_get_contents($root . '/scripts/setup-llama-cpp.sh');

    expect($script)->toContain('LLAMA_CPP_FFI_TAG="b9174"')
        ->toContain('git clone --depth 1 --branch "$LLAMA_CPP_FFI_TAG"')
        ->not->toContain('pull --ff-only');
});
