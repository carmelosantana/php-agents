<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStateSnapshot;
use Tests\Support\Runtime\Native\FakeLlamaCppNativeApi;

test('native runtime opens configured aliases and merges discovered defaults', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->registerModel(
        '/models/local.gguf',
        new RuntimeModelMetadata(
            id: 'local',
            name: 'Discovered Local',
            path: '/models/local.gguf',
            family: 'llama',
            contextWindow: 8192,
            maxTokens: 4096,
            defaultTemplate: 'chatml',
        ),
    );

    $runtime = new LlamaCppNativeRuntime(
        $api,
        [new RuntimeModelMetadata(
            id: 'local',
            name: 'Configured Local',
            path: '/models/local.gguf',
            aliases: ['local-alias'],
            supportsTools: true,
            defaultToolParser: 'json',
        )],
        ['threads' => 4],
    );

    $handle = $runtime->open('local-alias', ['numCtx' => 4096]);

    expect($runtime->models())->toHaveCount(1)
        ->and($api->backendInitialized)->toBeTrue()
        ->and($handle->model()->id)->toBe('local')
        ->and($handle->model()->name)->toBe('Configured Local')
        ->and($handle->model()->defaultTemplate)->toBe('chatml')
        ->and($handle->model()->defaultToolParser)->toBe('json')
        ->and($handle->model()->supportsTools)->toBeTrue()
        ->and($api->openContextCalls[0]['options']['threads'])->toBe(4)
        ->and($api->openContextCalls[0]['options']['numCtx'])->toBe(4096);
});

test('native runtime supports direct path fallback', function () {
    $api = new FakeLlamaCppNativeApi();
    $directory = sys_get_temp_dir() . '/php-agents-native-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    $modelPath = $directory . '/direct.gguf';
    file_put_contents($modelPath, 'model');

    $api->registerModel(
        $modelPath,
        new RuntimeModelMetadata(id: 'direct.gguf', name: 'Direct Model', path: $modelPath),
    );

    try {
        $runtime = new LlamaCppNativeRuntime($api);
        $handle = $runtime->open($modelPath);

        expect($handle->model()->path)->toBe($modelPath)
            ->and($handle->model()->name)->toBe('Direct Model');
    } finally {
        @unlink($modelPath);
        @rmdir($directory);
    }
});

test('native runtime delegates tokenization and state operations', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->registerModel(
        '/models/state.gguf',
        new RuntimeModelMetadata(id: 'stateful', name: 'Stateful', path: '/models/state.gguf'),
        [
            'tokens' => ['Hello' => [101, 102]],
            'detokenized' => ['101,102' => 'Hello'],
            'state' => 'model-state',
        ],
    );

    $runtime = new LlamaCppNativeRuntime($api, [new RuntimeModelMetadata(id: 'stateful', name: 'Stateful', path: '/models/state.gguf')]);
    $handle = $runtime->open('stateful');

    expect($handle->tokenize('Hello'))->toBe([101, 102])
        ->and($handle->detokenize([101, 102]))->toBe('Hello')
        ->and($handle->snapshotState()->bytes)->toBe('model-state');

    $handle->restoreState(new RuntimeStateSnapshot('restored-state'));
    $handle->restoreSequenceState('seq-1', new RuntimeStateSnapshot('sequence-state', 'seq-1'));

    expect($handle->snapshotState()->bytes)->toBe('restored-state')
        ->and($handle->snapshotSequenceState('seq-1')->bytes)->toBe('sequence-state');
});

test('native runtime aggregates generated chunks into a completion result', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->registerModel(
        '/models/generate.gguf',
        new RuntimeModelMetadata(id: 'generate', name: 'Generate', path: '/models/generate.gguf'),
        [
            'responses' => [
                '*' => [
                    new RuntimeCompletionChunk(content: 'Hello'),
                    new RuntimeCompletionChunk(content: ' world', finishReason: RuntimeFinishReason::Stop),
                ],
            ],
        ],
    );

    $runtime = new LlamaCppNativeRuntime($api, [new RuntimeModelMetadata(id: 'generate', name: 'Generate', path: '/models/generate.gguf')]);
    $handle = $runtime->open('generate');
    $result = $handle->generate(new RuntimeCompletionRequest('Say hello'));

    expect($result->content)->toBe('Hello world')
        ->and($result->finishReason)->toBe(RuntimeFinishReason::Stop);
});

test('native runtime exposes streaming chunks', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->registerModel(
        '/models/stream.gguf',
        new RuntimeModelMetadata(id: 'stream', name: 'Stream', path: '/models/stream.gguf'),
        [
            'responses' => [
                '*' => [
                    new RuntimeCompletionChunk(content: 'A'),
                    new RuntimeCompletionChunk(content: 'B', finishReason: RuntimeFinishReason::Stop),
                ],
            ],
        ],
    );

    $runtime = new LlamaCppNativeRuntime($api, [new RuntimeModelMetadata(id: 'stream', name: 'Stream', path: '/models/stream.gguf')]);
    $handle = $runtime->open('stream');
    $chunks = iterator_to_array($handle->stream(new RuntimeCompletionRequest('stream it')));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('A')
        ->and($chunks[1]->finishReason)->toBe(RuntimeFinishReason::Stop);
});

test('native runtime forwards multimodal requests to the native api', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->registerModel(
        '/models/vision.gguf',
        new RuntimeModelMetadata(id: 'vision', name: 'Vision', path: '/models/vision.gguf', supportsVision: true),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'image result')]]],
    );

    $runtime = new LlamaCppNativeRuntime($api, [new RuntimeModelMetadata(id: 'vision', name: 'Vision', path: '/models/vision.gguf', supportsVision: true)]);
    $handle = $runtime->open('vision');
    $result = $handle->generate(new RuntimeCompletionRequest(
        prompt: 'Describe [image]',
        images: [new \CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput('image-0', 'image/png', 'png-bytes')],
    ));

    expect($result->content)->toBe('image result')
        ->and($api->lastCompletionRequest?->images)->toHaveCount(1);
});

test('native runtime rejects unsupported strict structured output', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->registerModel('/models/strict.gguf', new RuntimeModelMetadata(id: 'strict', name: 'Strict', path: '/models/strict.gguf'));

    $runtime = new LlamaCppNativeRuntime($api, [new RuntimeModelMetadata(id: 'strict', name: 'Strict', path: '/models/strict.gguf')]);
    $handle = $runtime->open('strict');

    expect(fn() => $handle->generate(new RuntimeCompletionRequest(
        prompt: 'Return JSON',
        structuredOutput: new \CarmeloSantana\PHPAgents\Runtime\RuntimeStructuredOutput(
            name: 'json',
            schema: ['type' => 'object', 'properties' => []],
            strict: true,
        ),
    )))->toThrow(RuntimeException::class, 'Strict structured output mode');
});

test('native runtime handle close is enforced and frees resources', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->registerModel('/models/close.gguf', new RuntimeModelMetadata(id: 'closeable', name: 'Closeable', path: '/models/close.gguf'));

    $runtime = new LlamaCppNativeRuntime($api, [new RuntimeModelMetadata(id: 'closeable', name: 'Closeable', path: '/models/close.gguf')]);
    $handle = $runtime->open('closeable');
    $handle->close();

    expect($api->closedModels)->toBe(['/models/close.gguf'])
        ->and($api->closedContexts)->toHaveCount(1)
        ->and(fn() => $handle->tokenize('Hi'))->toThrow(RuntimeException::class, 'handle is closed');
});

test('native runtime reports unavailable api and rejects open', function () {
    $api = new FakeLlamaCppNativeApi();
    $api->available = false;
    $runtime = new LlamaCppNativeRuntime($api);

    expect($runtime->isAvailable())->toBeFalse()
        ->and(fn() => $runtime->open('missing'))
        ->toThrow(RuntimeException::class, 'runtime is unavailable');
});