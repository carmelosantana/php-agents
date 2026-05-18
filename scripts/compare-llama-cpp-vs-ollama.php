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

function normalizeOllamaModel(string $value): string
{
    return str_starts_with($value, 'ollama/')
        ? substr($value, strlen('ollama/'))
        : $value;
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

function tokensPerSecond(?int $tokens, ?int $durationNs): ?float
{
    if ($tokens === null || $tokens <= 0 || $durationNs === null || $durationNs <= 0) {
        return null;
    }

    return round($tokens / ($durationNs / 1_000_000_000), 2);
}

/**
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function summarizeOllamaGenerateResponse(array $response): array
{
    $evalCount = isset($response['eval_count']) && is_numeric($response['eval_count'])
        ? (int) $response['eval_count']
        : null;
    $evalDuration = isset($response['eval_duration']) && is_numeric($response['eval_duration'])
        ? (int) $response['eval_duration']
        : null;
    $promptEvalCount = isset($response['prompt_eval_count']) && is_numeric($response['prompt_eval_count'])
        ? (int) $response['prompt_eval_count']
        : null;
    $promptEvalDuration = isset($response['prompt_eval_duration']) && is_numeric($response['prompt_eval_duration'])
        ? (int) $response['prompt_eval_duration']
        : null;
    $loadDuration = isset($response['load_duration']) && is_numeric($response['load_duration'])
        ? (int) $response['load_duration']
        : null;
    $totalDuration = isset($response['total_duration']) && is_numeric($response['total_duration'])
        ? (int) $response['total_duration']
        : null;

    return [
        'finishReason' => is_string($response['done_reason'] ?? null) ? $response['done_reason'] : ((bool) ($response['done'] ?? false) ? 'stop' : 'unknown'),
        'promptEvalCount' => $promptEvalCount,
        'promptEvalDurationNs' => $promptEvalDuration,
        'evalCount' => $evalCount,
        'evalDurationNs' => $evalDuration,
        'loadDurationNs' => $loadDuration,
        'totalDurationNs' => $totalDuration,
        'tokensPerSecond' => tokensPerSecond($evalCount, $evalDuration),
        'contentPreview' => substr((string) ($response['response'] ?? ''), 0, 200),
    ];
}

if (!extension_loaded('FFI')) {
    fwrite(STDERR, "The FFI extension must be enabled to compare llama.cpp with Ollama.\n");
    exit(1);
}

$libraryPath = requireEnv('LLAMA_CPP_LIB_PATH');
$modelPath = requireEnv('LLAMA_CPP_MODEL_PATH');
$ollamaModel = normalizeOllamaModel(requireEnv('OLLAMA_MODEL'));
$ollamaBaseUrl = envOrNull('OLLAMA_BASE_URL') ?? 'http://localhost:11434/v1';
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
$keepAlive = envOrNull('OLLAMA_RAW_COMPARE_KEEP_ALIVE') ?? '5m';

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

$generateUrl = preg_replace('#/v1$#', '', rtrim($ollamaBaseUrl, '/')) . '/api/generate';

$unloadOllama = static function () use ($httpClient, $generateUrl, $ollamaModel): void {
    $httpClient->request('POST', $generateUrl, [
        'json' => [
            'model' => $ollamaModel,
            'prompt' => '',
            'keep_alive' => 0,
            'stream' => false,
        ],
    ])->toArray(false);
};

$nativeWarmHandle = $runtime->open('local-compare');
try {
    $nativeWarmBench = bench(function () use ($nativeWarmHandle, $prompt, $requestOptions) {
        return $nativeWarmHandle->generate(new RuntimeCompletionRequest(
            prompt: $prompt,
            options: $requestOptions,
        ));
    });
} finally {
    $nativeWarmHandle->close();
}

$ollamaRawOptions = [
    'temperature' => $temperature,
    'top_k' => $topK,
    'top_p' => $topP,
    'min_p' => $minP,
    'seed' => $seed,
    'num_predict' => $maxTokens,
    'num_ctx' => $numCtx,
    'num_thread' => $threads,
];

$unloadOllama();
$ollamaRawColdBench = bench(function () use ($httpClient, $generateUrl, $ollamaModel, $prompt, $ollamaRawOptions): array {
    return $httpClient->request('POST', $generateUrl, [
        'json' => [
            'model' => $ollamaModel,
            'prompt' => $prompt,
            'raw' => true,
            'stream' => false,
            'keep_alive' => 0,
            'options' => $ollamaRawOptions,
        ],
    ])->toArray();
});

$httpClient->request('POST', $generateUrl, [
    'json' => [
        'model' => $ollamaModel,
        'prompt' => '',
        'stream' => false,
        'keep_alive' => $keepAlive,
    ],
])->toArray(false);

$ollamaRawWarmBench = bench(function () use ($httpClient, $generateUrl, $ollamaModel, $prompt, $ollamaRawOptions, $keepAlive): array {
    return $httpClient->request('POST', $generateUrl, [
        'json' => [
            'model' => $ollamaModel,
            'prompt' => $prompt,
            'raw' => true,
            'stream' => false,
            'keep_alive' => $keepAlive,
            'options' => $ollamaRawOptions,
        ],
    ])->toArray();
});

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
        'keepAlive' => $keepAlive,
    ],
    'providerSurface' => [
        'nativeCold' => [
            'ms' => $localBench['ms'],
            'promptTokens' => $localBench['result']['promptTokens'],
            'finishReason' => $localBench['result']['finishReason'],
            'usage' => $localBench['result']['usage'],
            'tokensPerSecond' => $localBench['result']['usage']['completionTokens'] !== null && $localBench['ms'] > 0
                ? round($localBench['result']['usage']['completionTokens'] / ($localBench['ms'] / 1000), 2)
                : null,
            'contentPreview' => substr($localBench['result']['content'], 0, 200),
        ],
        'ollamaProvider' => [
            'ms' => $remoteBench['ms'],
            'finishReason' => $remote->finishReason->value,
            'usage' => $remote->usage === null ? null : [
                'promptTokens' => $remote->usage->promptTokens,
                'completionTokens' => $remote->usage->completionTokens,
                'totalTokens' => $remote->usage->totalTokens,
            ],
            'tokensPerSecond' => $remote->usage?->completionTokens !== null && $remoteBench['ms'] > 0
                ? round($remote->usage->completionTokens / ($remoteBench['ms'] / 1000), 2)
                : null,
            'contentPreview' => substr($remote->content, 0, 200),
        ],
    ],
    'rawParity' => [
        'native' => [
            'coldOpenGenerate' => [
                'ms' => $localBench['ms'],
                'promptTokens' => $localBench['result']['promptTokens'],
                'finishReason' => $localBench['result']['finishReason'],
                'usage' => $localBench['result']['usage'],
                'tokensPerSecond' => $localBench['result']['usage']['completionTokens'] !== null && $localBench['ms'] > 0
                    ? round($localBench['result']['usage']['completionTokens'] / ($localBench['ms'] / 1000), 2)
                    : null,
                'contentPreview' => substr($localBench['result']['content'], 0, 200),
            ],
            'warmHandleGenerate' => [
                'ms' => $nativeWarmBench['ms'],
                'finishReason' => $nativeWarmBench['result']->finishReason->value,
                'usage' => $nativeWarmBench['result']->usage === null ? null : [
                    'promptTokens' => $nativeWarmBench['result']->usage->promptTokens,
                    'completionTokens' => $nativeWarmBench['result']->usage->completionTokens,
                    'totalTokens' => $nativeWarmBench['result']->usage->totalTokens,
                ],
                'tokensPerSecond' => $nativeWarmBench['result']->usage?->completionTokens !== null && $nativeWarmBench['ms'] > 0
                    ? round($nativeWarmBench['result']->usage->completionTokens / ($nativeWarmBench['ms'] / 1000), 2)
                    : null,
                'contentPreview' => substr($nativeWarmBench['result']->content, 0, 200),
            ],
        ],
        'ollamaRaw' => [
            'coldGenerate' => ['ms' => $ollamaRawColdBench['ms']] + summarizeOllamaGenerateResponse($ollamaRawColdBench['result']),
            'warmGenerate' => ['ms' => $ollamaRawWarmBench['ms']] + summarizeOllamaGenerateResponse($ollamaRawWarmBench['result']),
        ],
    ],
    'notes' => [
        'providerSurface compares php-agents native llama.cpp with php-agents OllamaProvider and is useful for end-to-end provider behavior, not strict quality parity.',
        'rawParity compares native llama.cpp with Ollama /api/generate raw mode using the same plain prompt and matched sampling settings.',
        'For exact same-binary parity, import the same GGUF into Ollama before trusting the comparison as an engine-only result.',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;