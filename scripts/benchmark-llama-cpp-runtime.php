<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\FfiLlamaCppNativeApi;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStructuredOutput;

require dirname(__DIR__) . '/vendor/autoload.php';

function envOrNull(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

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
$projectorPath = envOrNull('LLAMA_CPP_MMPROJ_PATH');
$visionImagePath = envOrNull('LLAMA_CPP_VISION_IMAGE_PATH');
$threads = max(1, (int) (envOrNull('LLAMA_CPP_THREADS') ?? '2'));
$numCtx = max(512, (int) (envOrNull('LLAMA_CPP_NUM_CTX') ?? '4096'));
$prompt = envOrNull('LLAMA_CPP_BENCH_PROMPT') ?? 'Write one short sentence about direct local inference.';

$runtime = new LlamaCppNativeRuntime(
    new FfiLlamaCppNativeApi($libraryPath, $mtmdLibraryPath),
    [new RuntimeModelMetadata(
        id: 'benchmark',
        name: 'Benchmark Model',
        path: $modelPath,
        supportsVision: $projectorPath !== null,
        projectorPath: $projectorPath,
        extras: [
            'supportsStructuredOutput' => true,
            'structuredOutputModes' => ['json_schema'],
        ],
    )],
    [
        'threads' => $threads,
        'numCtx' => $numCtx,
    ],
);

$report = [
    'environment' => [
        'modelPath' => $modelPath,
        'libraryPath' => $libraryPath,
        'mtmdLibraryPath' => $mtmdLibraryPath,
        'projectorPath' => $projectorPath,
        'threads' => $threads,
        'numCtx' => $numCtx,
    ],
];

$open = bench(fn() => $runtime->open('benchmark'));
$handle = $open['result'];
$report['open'] = ['ms' => $open['ms']];

try {
    $tokenize = bench(fn() => $handle->tokenize($prompt));
    $generate = bench(fn() => $handle->generate(new RuntimeCompletionRequest(
        prompt: $prompt,
        options: ['temperature' => 0.0, 'maxTokens' => 64],
    )));
    $structured = bench(fn() => $handle->generate(new RuntimeCompletionRequest(
        prompt: 'Return JSON with keys status and ok.',
        structuredOutput: new RuntimeStructuredOutput(
            name: 'status',
            schema: [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string'],
                    'ok' => ['type' => 'boolean'],
                ],
                'required' => ['status', 'ok'],
                'additionalProperties' => false,
            ],
            strict: true,
        ),
        options: ['temperature' => 0.0, 'maxTokens' => 48],
    )));

    $report['tokenize'] = [
        'ms' => $tokenize['ms'],
        'tokens' => count($tokenize['result']),
    ];
    $report['generate'] = [
        'ms' => $generate['ms'],
        'completionTokens' => $generate['result']->usage?->completionTokens,
        'contentPreview' => substr($generate['result']->content, 0, 120),
    ];
    $report['structured'] = [
        'ms' => $structured['ms'],
        'contentPreview' => substr($structured['result']->content, 0, 120),
    ];

    if ($projectorPath !== null && $visionImagePath !== null && is_file($visionImagePath)) {
        $vision = bench(fn() => $handle->generate(new RuntimeCompletionRequest(
            prompt: 'Describe [image] in one short sentence.',
            images: [new RuntimeImageInput(
                id: 'benchmark-image',
                mimeType: mime_content_type($visionImagePath) ?: 'application/octet-stream',
                bytes: (string) file_get_contents($visionImagePath),
            )],
            options: ['temperature' => 0.0, 'maxTokens' => 64],
        )));

        $report['vision'] = [
            'ms' => $vision['ms'],
            'contentPreview' => substr($vision['result']->content, 0, 120),
        ];
    } else {
        $report['vision'] = ['skipped' => true];
    }
} finally {
    $handle->close();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;