<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\FfiLlamaCppNativeApi;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function envOrNull(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

function requireEnv(string $name): string
{
    $value = envOrNull($name);
    if ($value === null) {
        fwrite(STDERR, "Required environment variable is missing: {$name}\n");
        exit(1);
    }

    return $value;
}

function envInt(string $name, int $default): int
{
    $value = envOrNull($name);

    return $value !== null && is_numeric($value) ? (int) $value : $default;
}

function envFloat(string $name, float $default): float
{
    $value = envOrNull($name);

    return $value !== null && is_numeric($value) ? (float) $value : $default;
}

function envBool(string $name, bool $default): bool
{
    $value = envOrNull($name);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
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

if (!extension_loaded('FFI')) {
    fwrite(STDERR, "The FFI extension must be enabled to compare llama.cpp with Ollama.\n");
    exit(1);
}

$libraryPath = requireEnv('LLAMA_CPP_LIB_PATH');
$modelPath = requireEnv('LLAMA_CPP_MODEL_PATH');
$ollamaModel = requireEnv('OLLAMA_MODEL');
$ollamaBaseUrl = envOrNull('OLLAMA_BASE_URL') ?? 'https://ollama:11434/v1';
$mtmdLibraryPath = envOrNull('LLAMA_CPP_MTMD_LIB_PATH');
$prompt = envOrNull('LLAMA_CPP_COMPARE_PROMPT') ?? 'Write a short PHP function that returns the Fibonacci number for n.';
$threads = max(1, envInt('LLAMA_CPP_THREADS', 4));
$numCtx = max(1024, envInt('LLAMA_CPP_NUM_CTX', 32768));
$maxTokens = max(1, envInt('LLAMA_CPP_COMPARE_MAX_TOKENS', 256));
$temperature = envFloat('LLAMA_CPP_COMPARE_TEMPERATURE', 0.0);
$topK = envInt('LLAMA_CPP_COMPARE_TOP_K', 20);
$topP = envFloat('LLAMA_CPP_COMPARE_TOP_P', 0.8);
$minP = envFloat('LLAMA_CPP_COMPARE_MIN_P', 0.0);
$seed = envInt('LLAMA_CPP_COMPARE_SEED', 123);

$runtime = new LlamaCppNativeRuntime(
    new FfiLlamaCppNativeApi($libraryPath, $mtmdLibraryPath),
    [new RuntimeModelMetadata(
        id: 'local-compare',
        name: basename($modelPath),
        path: $modelPath,
        contextWindow: $numCtx,
        maxTokens: $maxTokens,
    )],
    [
        'threads' => $threads,
        'numCtx' => $numCtx,
    ],
);

$requestOptions = [
    'temperature' => $temperature,
    'top_k' => $topK,
    'top_p' => $topP,
    'min_p' => $minP,
    'seed' => $seed,
    'maxTokens' => $maxTokens,
];

$localBench = bench(function () use ($runtime, $prompt, $requestOptions): array {
    $handle = $runtime->open('local-compare');

    try {
        $promptTokens = count($handle->tokenize($prompt));
        $result = $handle->generate(new RuntimeCompletionRequest(
            prompt: $prompt,
            options: $requestOptions,
        ));

        return [
            'promptTokens' => $promptTokens,
            'content' => $result->content,
            'finishReason' => $result->finishReason->value,
            'usage' => $result->usage === null ? null : [
                'promptTokens' => $result->usage->promptTokens,
                'completionTokens' => $result->usage->completionTokens,
                'totalTokens' => $result->usage->totalTokens,
            ],
        ];
    } finally {
        $handle->close();
    }
});

$httpClient = HttpClient::create([
    'timeout' => 600,
    'verify_peer' => envBool('OLLAMA_VERIFY_PEER', true),
    'verify_host' => envBool('OLLAMA_VERIFY_HOST', true),
]);

$provider = new OllamaProvider(
    model: $ollamaModel,
    baseUrl: $ollamaBaseUrl,
    httpClient: $httpClient,
    numCtx: $numCtx,
);

$remoteBench = bench(fn() => $provider->chat(
    [new UserMessage($prompt)],
    [],
    [
        'temperature' => $temperature,
        'top_k' => $topK,
        'top_p' => $topP,
        'min_p' => $minP,
        'seed' => $seed,
        'max_tokens' => $maxTokens,
        'num_ctx' => $numCtx,
    ],
));

$remote = $remoteBench['result'];

$report = [
    'prompt' => $prompt,
    'config' => [
        'llamaCppModelPath' => $modelPath,
        'ollamaBaseUrl' => $ollamaBaseUrl,
        'ollamaModel' => $ollamaModel,
        'threads' => $threads,
        'numCtx' => $numCtx,
        'maxTokens' => $maxTokens,
        'temperature' => $temperature,
        'topK' => $topK,
        'topP' => $topP,
        'minP' => $minP,
        'seed' => $seed,
    ],
    'local' => [
        'ms' => $localBench['ms'],
        'promptTokens' => $localBench['result']['promptTokens'],
        'finishReason' => $localBench['result']['finishReason'],
        'usage' => $localBench['result']['usage'],
        'contentPreview' => substr($localBench['result']['content'], 0, 200),
    ],
    'ollama' => [
        'ms' => $remoteBench['ms'],
        'finishReason' => $remote->finishReason->value,
        'usage' => $remote->usage === null ? null : [
            'promptTokens' => $remote->usage->promptTokens,
            'completionTokens' => $remote->usage->completionTokens,
            'totalTokens' => $remote->usage->totalTokens,
        ],
        'contentPreview' => substr($remote->content, 0, 200),
    ],
    'notes' => [
        'This compares php-agents native llama.cpp against php-agents OllamaProvider.',
        'For exact same-GGUF parity, use Ollama raw mode on /api/generate with the same GGUF imported into Ollama.',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;