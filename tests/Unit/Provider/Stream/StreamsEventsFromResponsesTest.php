<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * @param list<Response> $chunks
 * @return list<object>
 */
function eventsFromChunks(array $chunks): array
{
    $provider = new class($chunks) {
        use StreamsEventsFromResponses;

        /** @param list<Response> $chunks */
        public function __construct(private array $chunks) {}

        /** @return iterable<Response> */
        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield from $this->chunks;
        }
    };

    return iterator_to_array($provider->streamEvents([]), false);
}

it('converts text chunks and a terminal ToolUse chunk into events', function () {
    $events = eventsFromChunks([
        new Response('a', ProviderFinishReason::Stop),
        new Response('b', ProviderFinishReason::Stop),
        new Response(
            '',
            ProviderFinishReason::ToolUse,
            toolCalls: [new ToolCall('id', 'x', ['q' => 1])],
            usage: new Usage(3, 2, 5),
        ),
    ]);

    expect($events)->toHaveCount(6)
        ->and($events[0])->toEqual(new TextDelta('a'))
        ->and($events[1])->toEqual(new TextDelta('b'))
        ->and($events[2])->toEqual(new ToolInputStart(0, 'id', 'x'))
        ->and($events[3])->toEqual(new ToolInputDelta(0, 'id', '{"q":1}'))
        ->and($events[4])->toEqual(new ToolInputEnd(0, 'id', 'x', ['q' => 1]))
        ->and($events[5])->toBeInstanceOf(Finish::class)
        ->and($events[5]->reason)->toBe(ProviderFinishReason::ToolUse)
        ->and($events[5]->rawReason)->toBe('')
        ->and($events[5]->usage)->toEqual(new Usage(3, 2, 5));
});

it('keeps an earlier failure reason over a later ToolUse', function () {
    $events = eventsFromChunks([
        new Response('a', ProviderFinishReason::Error),
        new Response('', ProviderFinishReason::ToolUse, toolCalls: [new ToolCall('id', 'x', [])]),
    ]);

    expect(end($events))->toBeInstanceOf(Finish::class)
        ->and(end($events)->reason)->toBe(ProviderFinishReason::Error);
});

it('lets the last non-Stop reason win when nothing failed', function () {
    $events = eventsFromChunks([
        new Response('', ProviderFinishReason::ToolUse),
        new Response('', ProviderFinishReason::MaxTokens),
        new Response('', ProviderFinishReason::Stop),
    ]);

    expect(end($events)->reason)->toBe(ProviderFinishReason::MaxTokens);
});

it('defaults the reason to ToolUse when calls arrived on Stop chunks, else Stop', function () {
    $withCalls = eventsFromChunks([
        new Response('', ProviderFinishReason::Stop, toolCalls: [new ToolCall('id', 'x', [])]),
    ]);
    $plain = eventsFromChunks([new Response('hi', ProviderFinishReason::Stop)]);

    expect(end($withCalls)->reason)->toBe(ProviderFinishReason::ToolUse)
        ->and(end($plain)->reason)->toBe(ProviderFinishReason::Stop)
        ->and(end($plain)->usage)->toBeNull();
});

it('emits reasoning before text and encodes empty arguments as {}', function () {
    $events = eventsFromChunks([
        new Response('t', ProviderFinishReason::Stop, reasoning: 'r'),
        new Response('', ProviderFinishReason::Stop, toolCalls: [new ToolCall('c1', 'a', [])]),
        new Response('', ProviderFinishReason::ToolUse, toolCalls: [
            new ToolCall('c2', 'b', ['k' => 'v'], ['thoughtSignature' => 'sig']),
        ]),
    ]);

    expect($events[0])->toEqual(new ReasoningDelta('r'))
        ->and($events[1])->toEqual(new TextDelta('t'))
        ->and($events[3])->toEqual(new ToolInputDelta(0, 'c1', '{}'))
        ->and($events[5])->toEqual(new ToolInputStart(1, 'c2', 'b'))
        ->and($events[7])->toEqual(new ToolInputEnd(1, 'c2', 'b', ['k' => 'v'], ['thoughtSignature' => 'sig']));
});

it('max-merges usage per field and keeps the last non-empty model and content blocks', function () {
    $events = eventsFromChunks([
        new Response('', ProviderFinishReason::Stop, model: 'm1', usage: new Usage(10, 0, 10, cacheReadTokens: 4), contentBlocks: [['type' => 'text']]),
        new Response('', ProviderFinishReason::Stop, model: 'm2', usage: new Usage(0, 7, 0, reasoningTokens: 2)),
        new Response('', ProviderFinishReason::Stop, model: ''),
    ]);

    $finish = end($events);

    expect($finish->usage)->toEqual(new Usage(10, 7, 17, cacheReadTokens: 4, cacheWriteTokens: null, reasoningTokens: 2))
        ->and($finish->model)->toBe('m2')
        ->and($finish->contentBlocks)->toBe([['type' => 'text']]);
});

it('emits only Finish for an empty stream', function () {
    expect(eventsFromChunks([]))->toEqual([new Finish(ProviderFinishReason::Stop)]);
});
