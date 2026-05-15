<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\FfiLlamaCppNativeApi;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStructuredOutput;

function nativeRuntimeEnv(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

function skipUnlessNativeTextRuntimeConfigured(): array
{
    $libraryPath = nativeRuntimeEnv('LLAMA_CPP_LIB_PATH');
    $modelPath = nativeRuntimeEnv('LLAMA_CPP_MODEL_PATH');

    if (!extension_loaded('FFI') || $libraryPath === null || $modelPath === null) {
        test()->markTestSkipped('Set LLAMA_CPP_LIB_PATH and LLAMA_CPP_MODEL_PATH with FFI enabled to run native llama.cpp integration tests.');
    }

    return [$libraryPath, $modelPath, nativeRuntimeEnv('LLAMA_CPP_MTMD_LIB_PATH')];
}

function openNativeIntegrationHandle(array $metadataExtras = []): array
{
    [$libraryPath, $modelPath, $mtmdLibraryPath] = skipUnlessNativeTextRuntimeConfigured();

    $metadata = new RuntimeModelMetadata(
        id: 'integration',
        name: 'Integration Model',
        path: $modelPath,
        supportsVision: (bool) ($metadataExtras['supportsVision'] ?? false),
        projectorPath: $metadataExtras['projectorPath'] ?? null,
        extras: array_filter([
            'supportsStructuredOutput' => $metadataExtras['supportsStructuredOutput'] ?? true,
            'structuredOutputModes' => $metadataExtras['structuredOutputModes'] ?? ['json_schema'],
        ], static fn(mixed $value): bool => $value !== null),
    );

    $runtime = new LlamaCppNativeRuntime(
        new FfiLlamaCppNativeApi($libraryPath, $mtmdLibraryPath),
        [$metadata],
        [
            'threads' => 2,
            'numCtx' => 4096,
        ],
    );

    return [$runtime, $runtime->open('integration')];
}

test('ffi native runtime can open a real model and tokenize text when configured', function () {
    [$runtime, $handle] = openNativeIntegrationHandle();

    try {
        $tokens = $handle->tokenize('Hello from php-agents');
        $detokenized = $handle->detokenize($tokens);

        expect($runtime->isAvailable())->toBeTrue()
            ->and($handle->model()->path)->not->toBe('')
            ->and($handle->model()->contextWindow)->toBeGreaterThan(0)
            ->and($tokens)->not->toBe([])
            ->and($detokenized)->toBeString();
    } finally {
        $handle->close();
    }
});

test('ffi native runtime can generate text when configured', function () {
    [, $handle] = openNativeIntegrationHandle();

    try {
        $result = $handle->generate(new RuntimeCompletionRequest(
            prompt: 'Reply with exactly one short sentence about local inference.',
            options: [
                'temperature' => 0.0,
                'maxTokens' => 32,
            ],
        ));

        expect($result->content)->toBeString()
            ->and(trim($result->content))->not->toBe('');
    } finally {
        $handle->close();
    }
});

test('ffi native runtime can enforce strict json output when configured', function () {
    [, $handle] = openNativeIntegrationHandle();

    try {
        $result = $handle->generate(new RuntimeCompletionRequest(
            prompt: 'Return JSON with keys status and ok where status is "ready" and ok is true.',
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
            options: [
                'temperature' => 0.0,
                'maxTokens' => 48,
            ],
        ));

        $decoded = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveKeys(['status', 'ok']);
    } finally {
        $handle->close();
    }
});

test('ffi native runtime can process image input when projector and sample image are configured', function () {
    $projectorPath = nativeRuntimeEnv('LLAMA_CPP_MMPROJ_PATH');
    $imagePath = nativeRuntimeEnv('LLAMA_CPP_VISION_IMAGE_PATH');

    if ($projectorPath === null || $imagePath === null || !is_file($imagePath)) {
        $this->markTestSkipped('Set LLAMA_CPP_MMPROJ_PATH and LLAMA_CPP_VISION_IMAGE_PATH to run native llama.cpp vision integration tests.');
    }

    [, $handle] = openNativeIntegrationHandle([
        'supportsVision' => true,
        'projectorPath' => $projectorPath,
    ]);

    try {
        $result = $handle->generate(new RuntimeCompletionRequest(
            prompt: 'Describe [image] in one short sentence.',
            images: [new RuntimeImageInput(
                id: 'vision-sample',
                mimeType: mime_content_type($imagePath) ?: 'application/octet-stream',
                bytes: (string) file_get_contents($imagePath),
            )],
            options: [
                'temperature' => 0.0,
                'maxTokens' => 48,
            ],
        ));

        expect(trim($result->content))->not->toBe('');
    } finally {
        $handle->close();
    }
});