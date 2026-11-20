<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

it('builds a ToolCall from ToolInputEnd', function () {
    $end = new ToolInputEnd(
        index: 1,
        id: 'call_1',
        name: 'search',
        arguments: ['q' => 'php'],
        metadata: ['thoughtSignature' => 'sig'],
    );

    $call = $end->toToolCall();

    expect($call)->toBeInstanceOf(ToolCall::class)
        ->and($call->id)->toBe('call_1')
        ->and($call->name)->toBe('search')
        ->and($call->arguments)->toBe(['q' => 'php'])
        ->and($call->metadata)->toBe(['thoughtSignature' => 'sig']);
});

it('defaults ToolInputEnd metadata to empty', function () {
    expect((new ToolInputEnd(0, 'c', 'n', []))->toToolCall()->metadata)->toBe([]);
});

it('marks every event as a StreamEvent', function () {
    $events = [
        new TextDelta('hi'),
        new ReasoningDelta('think'),
        new ToolInputStart(0, 'c', 'n'),
        new ToolInputDelta(0, 'c', '{"a"'),
        new ToolInputEnd(0, 'c', 'n', ['a' => 1]),
        new Tick(),
        new Finish(ProviderFinishReason::Stop),
    ];

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(StreamEvent::class);
    }
});

it('carries the fields it was built with', function () {
    $usage = new Usage(1, 2, 3);
    $finish = new Finish(ProviderFinishReason::ToolUse, 'tool_calls', $usage, 'gpt-x', [['type' => 'text']]);
    $delta = new ToolInputDelta(2, 'call_9', '{"x":');
    $start = new ToolInputStart(2, 'call_9', 'calc');

    expect((new TextDelta('a'))->delta)->toBe('a')
        ->and((new ReasoningDelta('b'))->delta)->toBe('b')
        ->and([$start->index, $start->id, $start->name])->toBe([2, 'call_9', 'calc'])
        ->and([$delta->index, $delta->id, $delta->delta])->toBe([2, 'call_9', '{"x":'])
        ->and($finish->reason)->toBe(ProviderFinishReason::ToolUse)
        ->and($finish->rawReason)->toBe('tool_calls')
        ->and($finish->usage)->toBe($usage)
        ->and($finish->model)->toBe('gpt-x')
        ->and($finish->contentBlocks)->toBe([['type' => 'text']]);
});

it('defaults Finish optional fields', function () {
    $finish = new Finish(ProviderFinishReason::Stop);

    expect($finish->rawReason)->toBe('')
        ->and($finish->usage)->toBeNull()
        ->and($finish->model)->toBe('')
        ->and($finish->contentBlocks)->toBe([]);
});
