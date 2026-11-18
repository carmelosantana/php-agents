<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

function assistantMessageTestBlocks(): array
{
    return [
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig1'],
        ['type' => 'text', 'text' => 'Looking it up.'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => (object) ['q' => 'php']],
    ];
}

test('contentBlocks defaults to an empty list', function () {
    expect((new AssistantMessage('hi'))->contentBlocks())->toBe([]);
});

test('contentBlocks returns the blocks given to the constructor', function () {
    $blocks = assistantMessageTestBlocks();

    expect((new AssistantMessage('Looking it up.', [], $blocks))->contentBlocks())->toBe($blocks);
});

test('OpenAI toArray ignores content blocks', function () {
    $toolCalls = [new ToolCall('toolu_1', 'lookup', ['q' => 'php'])];
    $withBlocks = new AssistantMessage('Looking it up.', $toolCalls, assistantMessageTestBlocks());
    $without = new AssistantMessage('Looking it up.', $toolCalls);

    expect($withBlocks->toArray())->toBe($without->toArray());
});

test('withoutContentBlocks keeps text and tool calls', function () {
    $toolCalls = [new ToolCall('toolu_1', 'lookup', ['q' => 'php'])];
    $message = new AssistantMessage('Looking it up.', $toolCalls, assistantMessageTestBlocks());

    $stripped = $message->withoutContentBlocks();

    expect($stripped->contentBlocks())->toBe([])
        ->and($stripped->content())->toBe('Looking it up.')
        ->and($stripped->toolCalls())->toBe($toolCalls)
        ->and($message->contentBlocks())->toHaveCount(3);
});

test('Response carries content blocks as its last parameter', function () {
    $blocks = assistantMessageTestBlocks();

    expect((new Response('x', ProviderFinishReason::Stop))->contentBlocks)->toBe([])
        ->and((new Response('x', ProviderFinishReason::Stop, [], '', null, '', $blocks))->contentBlocks)->toBe($blocks);
});
