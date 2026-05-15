<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\LocalModelHandleInterface;
use CarmeloSantana\PHPAgents\Contract\LocalModelRuntimeInterface;
use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStateSnapshot;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStructuredOutput;
use CarmeloSantana\PHPAgents\Runtime\RuntimeToolDefinition;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Tests\Support\Runtime\FakeLocalModelRuntime;

test('fake runtime implements the public runtime contracts', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(
        id: 'mini',
        name: 'Mini',
        supportsTools: true,
        aliases: ['mini-alias'],
    ));

    $handle = $runtime->open('mini-alias');

    expect($runtime)->toBeInstanceOf(LocalModelRuntimeInterface::class)
        ->and($handle)->toBeInstanceOf(LocalModelHandleInterface::class)
        ->and($runtime->models())->toHaveCount(1)
        ->and($handle->model()->id)->toBe('mini')
        ->and($handle->model()->supportsTools)->toBeTrue();
});

test('runtime completion result aggregates streamed chunks deterministically', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'coder', name: 'Coder', supportsTools: true, supportsReasoning: true),
        [
            'chunks' => [
                new RuntimeCompletionChunk(content: 'Hello '),
                new RuntimeCompletionChunk(reasoning: 'thinking...'),
                new RuntimeCompletionChunk(
                    content: 'world',
                    toolCalls: [new ToolCall('call_1', 'search_docs', ['query' => 'runtime'])],
                    finishReason: RuntimeFinishReason::ToolUse,
                    usage: new Usage(promptTokens: 11, completionTokens: 7, totalTokens: 18),
                    metadata: ['source' => 'fake-runtime'],
                    warnings: ['parser used fallback'],
                ),
            ],
        ],
    );

    $result = $runtime->open('coder')->generate(new RuntimeCompletionRequest(prompt: 'hi'));

    expect($result->content)->toBe('Hello world')
        ->and($result->reasoning)->toBe('thinking...')
        ->and($result->toolCalls)->toHaveCount(1)
        ->and($result->toolCalls[0]->name)->toBe('search_docs')
        ->and($result->finishReason)->toBe(RuntimeFinishReason::ToolUse)
        ->and($result->usage?->totalTokens)->toBe(18)
        ->and($result->metadata['source'])->toBe('fake-runtime')
        ->and($result->warnings)->toContain('parser used fallback');
});

test('fake runtime supports tokenization and state snapshot round trips', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'stateful', name: 'Stateful'),
        [
            'tokens' => ['Hello' => [101, 102]],
            'detokenized' => ['101,102' => 'Hello'],
            'state' => new RuntimeStateSnapshot('model-state'),
            'sequenceStates' => [
                'seq-1' => new RuntimeStateSnapshot('sequence-state', 'seq-1'),
            ],
        ],
    );

    $handle = $runtime->open('stateful');

    expect($handle->tokenize('Hello'))->toBe([101, 102])
        ->and($handle->detokenize([101, 102]))->toBe('Hello')
        ->and($handle->snapshotState()->bytes)->toBe('model-state')
        ->and($handle->snapshotSequenceState('seq-1')->bytes)->toBe('sequence-state');

    $handle->restoreState(new RuntimeStateSnapshot('restored-model-state'));
    $handle->restoreSequenceState('seq-2', new RuntimeStateSnapshot('restored-seq-state', 'seq-2'));

    expect($handle->snapshotState()->bytes)->toBe('restored-model-state')
        ->and($handle->snapshotSequenceState('seq-2')->bytes)->toBe('restored-seq-state');
});

test('fake runtime enforces capability gates for tools, images, and structured output', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(id: 'text-only', name: 'Text Only'));
    $handle = $runtime->open('text-only');

    $toolRequest = new RuntimeCompletionRequest(
        prompt: 'tool request',
        tools: [new RuntimeToolDefinition('search', 'Search docs')],
    );
    $imageRequest = new RuntimeCompletionRequest(
        prompt: 'image request',
        images: [new RuntimeImageInput('img-0', 'image/png', 'png-bytes')],
    );
    $structuredRequest = new RuntimeCompletionRequest(
        prompt: 'structured request',
        structuredOutput: new RuntimeStructuredOutput('answer', ['type' => 'object']),
    );

    expect(fn() => iterator_to_array($handle->stream($toolRequest)))
        ->toThrow(InvalidArgumentException::class, 'does not support tools');
    expect(fn() => iterator_to_array($handle->stream($imageRequest)))
        ->toThrow(InvalidArgumentException::class, 'does not support image input');
    expect(fn() => iterator_to_array($handle->stream($structuredRequest)))
        ->toThrow(RuntimeException::class, 'does not support structured output');
});