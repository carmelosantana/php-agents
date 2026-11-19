<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamProjector;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

it('projects events to one Response per delta and one terminal Response', function () {
    $responses = iterator_to_array(StreamProjector::toResponses([
        new TextDelta('a'),
        new Tick(),
        new TextDelta('b'),
        new ToolInputStart(0, 'id', 'x'),
        new ToolInputDelta(0, 'id', '{"q":1}'),
        new ToolInputEnd(0, 'id', 'x', ['q' => 1]),
        new Finish(ProviderFinishReason::ToolUse, '', new Usage(3, 2, 5), 'm', [['type' => 'text']]),
    ]), false);

    expect($responses)->toEqual([
        new Response('a', ProviderFinishReason::Stop),
        new Response('b', ProviderFinishReason::Stop),
        new Response(
            '',
            ProviderFinishReason::ToolUse,
            toolCalls: [new ToolCall('id', 'x', ['q' => 1])],
            model: 'm',
            usage: new Usage(3, 2, 5),
            contentBlocks: [['type' => 'text']],
        ),
    ]);
});

it('projects reasoning deltas onto the reasoning field', function () {
    $responses = iterator_to_array(StreamProjector::toResponses([
        new ReasoningDelta('r'),
        new Finish(ProviderFinishReason::Stop),
    ]), false);

    expect($responses)->toEqual([
        new Response('', ProviderFinishReason::Stop, reasoning: 'r'),
        new Response('', ProviderFinishReason::Stop),
    ]);
});

it('drops tool calls from the terminal Response unless the reason is ToolUse', function () {
    $responses = iterator_to_array(StreamProjector::toResponses([
        new ToolInputEnd(0, 'id', 'x', []),
        new Finish(ProviderFinishReason::MaxTokens),
    ]), false);

    expect($responses)->toEqual([new Response('', ProviderFinishReason::MaxTokens)]);
});
