<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\FfiLlamaCppNativeApi;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

require dirname(__DIR__) . '/vendor/autoload.php';

function envOrNull(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * @return array{ms: float, result: mixed}
 */
function bench(callable $callback): array
{
    $start = hrtime(true);
    $result = $callback();

    return [
        'ms' => round((hrtime(true) - $start) / 1_000_000, 3),
        'result' => $result,
    ];
}

/**
 * @param list<array{ms: float, finishReason: string, completionTokens: ?int, preview: string}> $runs
 */
function averageMs(array $runs): float
{
    return round(array_sum(array_column($runs, 'ms')) / max(1, count($runs)), 3);
}

/**
 * @param array{ms: float, finishReason: string, completionTokens: ?int, preview: string} $run
 */
function tokensPerSecond(array $run): ?float
{
    $tokens = $run['completionTokens'];
    $ms = $run['ms'];

    if (!is_int($tokens) || $tokens <= 0 || $ms <= 0) {
        return null;
    }

    return round($tokens / ($ms / 1000), 2);
}

if (!extension_loaded('FFI')) {
    fwrite(STDERR, "The FFI extension must be enabled to run this benchmark.\n");
    exit(1);
}

$libraryPath = envOrNull('LLAMA_CPP_LIB_PATH');
$modelPath = envOrNull('LLAMA_CPP_MODEL_PATH');

if ($libraryPath === null || $modelPath === null) {
    fwrite(STDERR, "Set LLAMA_CPP_LIB_PATH and LLAMA_CPP_MODEL_PATH before running the benchmark.\n");
    exit(1);
}

$mtmdLibraryPath = envOrNull('LLAMA_CPP_MTMD_LIB_PATH');
$threads = max(1, (int) (envOrNull('LLAMA_CPP_THREADS') ?? '4'));
$numCtx = max(512, (int) (envOrNull('LLAMA_CPP_NUM_CTX') ?? '4096'));
$prompt = envOrNull('LLAMA_CPP_CACHE_BENCH_PROMPT')
    ?? 'Write a short PHP function that returns the Fibonacci number for n.';
$maxTokens = max(1, (int) (envOrNull('LLAMA_CPP_CACHE_BENCH_MAX_TOKENS') ?? '256'));
$runs = max(1, (int) (envOrNull('LLAMA_CPP_CACHE_BENCH_RUNS') ?? '3'));

$runtime = new LlamaCppNativeRuntime(
    new FfiLlamaCppNativeApi($libraryPath, $mtmdLibraryPath),
    [new RuntimeModelMetadata(
        id: 'cache-benchmark',
        name: 'Cache Benchmark Model',
        path: $modelPath,
        contextWindow: $numCtx,
        maxTokens: $maxTokens,
    )],
    [
        'threads' => $threads,
        'numCtx' => $numCtx,
    ],
);

$options = [
    'temperature' => 0.0,
    'top_k' => 20,
    'top_p' => 0.8,
    'min_p' => 0.0,
    'seed' => 123,
    'maxTokens' => $maxTokens,
];

$coldRuns = [];
for ($index = 0; $index < $runs; $index++) {
    $benchmarked = bench(function () use ($runtime, $prompt, $options) {
        $handle = $runtime->open('cache-benchmark');

        try {
            return $handle->generate(new RuntimeCompletionRequest(
                prompt: $prompt,
                options: $options,
            ));
        } finally {
            $handle->close();
        }
    });

    $result = $benchmarked['result'];
    $coldRuns[] = [
        'ms' => $benchmarked['ms'],
        'finishReason' => $result->finishReason->value,
        'completionTokens' => $result->usage?->completionTokens,
        'preview' => substr($result->content, 0, 80),
    ];
}

$warmRuns = [];
$handle = $runtime->open('cache-benchmark');

try {
    for ($index = 0; $index < $runs; $index++) {
        $benchmarked = bench(fn() => $handle->generate(new RuntimeCompletionRequest(
            prompt: $prompt,
            options: $options,
        )));

        $result = $benchmarked['result'];
        $warmRuns[] = [
            'ms' => $benchmarked['ms'],
            'finishReason' => $result->finishReason->value,
            'completionTokens' => $result->usage?->completionTokens,
            'preview' => substr($result->content, 0, 80),
        ];
    }
} finally {
    $handle->close();
}

$coldAverage = averageMs($coldRuns);
$warmAverage = averageMs($warmRuns);

$report = [
    'prompt' => $prompt,
    'config' => [
        'modelPath' => $modelPath,
        'threads' => $threads,
        'numCtx' => $numCtx,
        'maxTokens' => $maxTokens,
        'runsPerMode' => $runs,
    ],
    'coldOpenGenerate' => [
        'runs' => array_map(static fn(array $run): array => $run + ['tokensPerSecond' => tokensPerSecond($run)], $coldRuns),
        'averageMs' => $coldAverage,
    ],
    'warmHandleGenerate' => [
        'runs' => array_map(static fn(array $run): array => $run + ['tokensPerSecond' => tokensPerSecond($run)], $warmRuns),
        'averageMs' => $warmAverage,
    ],
    'deltaMs' => round($coldAverage - $warmAverage, 3),
    'speedupPercent' => $coldAverage > 0.0 ? round((($coldAverage - $warmAverage) / $coldAverage) * 100, 2) : 0.0,
    'notes' => [
        'Warm-handle runs reuse the already-open model/context but still clear KV cache between requests.',
        'This measures the safe caching win available today without relying on snapshot/restore APIs.',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;