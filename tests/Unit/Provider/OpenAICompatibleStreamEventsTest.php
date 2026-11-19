<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Support\Http\ScriptedStreamClient;

/**
 * @param list<array<string, mixed>> $chunks
 */
function openAIEventsBody(array $chunks, bool $done = true): string
{
    $lines = array_map(fn(array $c) => 'data: ' . json_encode($c), $chunks);
    if ($done) {
        $lines[] = 'data: [DONE]';
    }

    return implode("\n\n", $lines) . "\n\n";
}

/**
 * @param list<array<string, mixed>> $chunks
 * @return list<object>
 */
function openAIStreamEvents(array $chunks, bool $done = true): array
{
    $client = new MockHttpClient([new MockResponse(openAIEventsBody($chunks, $done), ['http_code' => 200])]);
    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    return array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));
}

/**
 * @param list<object> $events
 * @return list<class-string>
 */
function openAIEventClasses(array $events): array
{
    return array_map(fn(object $e) => $e::class, $events);
}

/**
 * A chunk carrying tool-call fragments for choice 0.
 *
 * @param list<array<string, mixed>> $toolCalls
 * @return array<string, mixed>
 */
function openAIToolChunk(array $toolCalls, ?string $finishReason = null): array
{
    return ['model' => 'gpt-4o-2024', 'choices' => [['delta' => ['tool_calls' => $toolCalls], 'finish_reason' => $finishReason]]];
}

/**
 * @return array<string, mixed>
 */
function openAIFinishChunk(string $finishReason): array
{
    return ['model' => 'gpt-4o-2024', 'choices' => [['delta' => [], 'finish_reason' => $finishReason]]];
}

test('streamEvents yields text deltas then one Finish carrying the usage chunk', function () {
    $events = openAIStreamEvents([
        ['model' => 'gpt-4o-2024', 'choices' => [['delta' => ['content' => 'He'], 'finish_reason' => null]]],
        ['model' => 'gpt-4o-2024', 'choices' => [['delta' => ['content' => 'llo'], 'finish_reason' => null]]],
        openAIFinishChunk('stop'),
        ['model' => 'gpt-4o-2024', 'choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7]],
    ]);

    expect(openAIEventClasses($events))->toBe([TextDelta::class, TextDelta::class, Finish::class])
        ->and($events[0]->delta)->toBe('He')
        ->and($events[1]->delta)->toBe('llo')
        ->and($events[2]->reason)->toBe(ProviderFinishReason::Stop)
        ->and($events[2]->rawReason)->toBe('stop')
        ->and($events[2]->model)->toBe('gpt-4o-2024')
        ->and($events[2]->contentBlocks)->toBe([])
        ->and($events[2]->usage)->toEqual(new Usage(5, 2, 7));
});

test('streamEvents yields ToolInputStart on the first fragment and ToolInputDelta with the id repeated', function () {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => '']]]),
        openAIToolChunk([['index' => 0, 'function' => ['arguments' => '{"a"']]]),
        openAIToolChunk([['index' => 0, 'function' => ['arguments' => ':1}']]]),
        openAIFinishChunk('tool_calls'),
    ]);

    expect($events)->toEqual([
        new ToolInputStart(0, 'c1', 'x'),
        new ToolInputDelta(0, 'c1', '{"a"'),
        new ToolInputDelta(0, 'c1', ':1}'),
        new ToolInputEnd(0, 'c1', 'x', ['a' => 1]),
        new Finish(ProviderFinishReason::ToolUse, 'tool_calls', null, 'gpt-4o-2024'),
    ]);
});

test('streamEvents keeps two interleaved calls well formed and ends them in index order', function () {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'first', 'arguments' => '{"a"']]]),
        openAIToolChunk([['index' => 1, 'id' => 'c2', 'function' => ['name' => 'second', 'arguments' => '{"b"']]]),
        openAIToolChunk([['index' => 0, 'function' => ['arguments' => ':1}']]]),
        openAIToolChunk([['index' => 1, 'function' => ['arguments' => ':2}']]]),
        openAIFinishChunk('tool_calls'),
    ]);

    expect($events)->toEqual([
        new ToolInputStart(0, 'c1', 'first'),
        new ToolInputDelta(0, 'c1', '{"a"'),
        new ToolInputStart(1, 'c2', 'second'),
        new ToolInputDelta(1, 'c2', '{"b"'),
        new ToolInputDelta(0, 'c1', ':1}'),
        new ToolInputDelta(1, 'c2', ':2}'),
        new ToolInputEnd(0, 'c1', 'first', ['a' => 1]),
        new ToolInputEnd(1, 'c2', 'second', ['b' => 2]),
        new Finish(ProviderFinishReason::ToolUse, 'tool_calls', null, 'gpt-4o-2024'),
    ]);
});

test('streamEvents opens a new call when a fragment brings a new id on an index already in use', function () {
    // Servers that send whole calls without an index default every call to index 0.
    $events = openAIStreamEvents([
        openAIToolChunk([
            ['id' => 'c1', 'function' => ['name' => 'first', 'arguments' => '{"a":1}']],
            ['id' => 'c2', 'function' => ['name' => 'second', 'arguments' => '{"b":2}']],
        ]),
        openAIFinishChunk('tool_calls'),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));

    expect($ends)->toEqual([
        new ToolInputEnd(0, 'c1', 'first', ['a' => 1]),
        new ToolInputEnd(1, 'c2', 'second', ['b' => 2]),
    ]);
});

test('streamEvents accepts arguments sent as a JSON object', function (array $arguments, array $expected) {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => (object) $arguments]]]),
        openAIFinishChunk('tool_calls'),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));
    $finish = $events[array_key_last($events)];

    expect($ends)->toEqual([new ToolInputEnd(0, 'c1', 'x', $expected)])
        ->and($finish->reason)->toBe(ProviderFinishReason::ToolUse);
})->with([
    'with keys' => [['a' => 1], ['a' => 1]],
    'empty' => [[], []],
]);

test('streamEvents ends calls in the order they opened, not by provider index', function () {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 1, 'id' => 'c2', 'function' => ['name' => 'second', 'arguments' => '{"b":2}']]]),
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'first', 'arguments' => '{"a":1}']]]),
        openAIFinishChunk('tool_calls'),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));

    expect($ends)->toEqual([
        new ToolInputEnd(0, 'c2', 'second', ['b' => 2]),
        new ToolInputEnd(1, 'c1', 'first', ['a' => 1]),
    ]);
});

test('streamEvents treats a repeated id on the same index as a continuation', function () {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => '{"a"']]]),
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['arguments' => ':1}']]]),
        openAIFinishChunk('tool_calls'),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));

    expect($ends)->toEqual([new ToolInputEnd(0, 'c1', 'x', ['a' => 1])]);
});

test('streamEvents decodes empty or whitespace arguments as an empty object', function (string $arguments) {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'now', 'arguments' => $arguments]]]),
        openAIFinishChunk('tool_calls'),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));
    $finish = $events[array_key_last($events)];

    expect($ends)->toEqual([new ToolInputEnd(0, 'c1', 'now', [])])
        ->and($finish->reason)->toBe(ProviderFinishReason::ToolUse);
})->with(['empty' => [''], 'whitespace' => ['  ']]);

test('streamEvents drops a call whose arguments are not a JSON object and finishes with Error', function (string $arguments) {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => $arguments]]]),
        openAIToolChunk([['index' => 1, 'id' => 'c2', 'function' => ['name' => 'y', 'arguments' => '{"ok":true}']]]),
        openAIFinishChunk('tool_calls'),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));
    $finish = $events[array_key_last($events)];

    expect($ends)->toEqual([new ToolInputEnd(1, 'c2', 'y', ['ok' => true])])
        ->and($finish)->toBeInstanceOf(Finish::class)
        ->and($finish->reason)->toBe(ProviderFinishReason::Error)
        ->and($finish->rawReason)->toBe('tool_calls');
})->with(['truncated' => ['{"a":'], 'array' => ['[1,2]'], 'scalar' => ['42']]);

test('streamEvents keeps the mapped reason when a truncated call ends on length', function () {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => '{"a":']]]),
        openAIFinishChunk('length'),
    ]);

    $finish = $events[array_key_last($events)];

    expect(array_filter($events, fn($e) => $e instanceof ToolInputEnd))->toBe([])
        ->and($finish->reason)->toBe(ProviderFinishReason::MaxTokens)
        ->and($finish->rawReason)->toBe('length');
});

test('streamEvents holds Finish for the usage-only chunk and reads cached and reasoning tokens', function () {
    $events = openAIStreamEvents([
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => '{}']]]),
        openAIFinishChunk('tool_calls'),
        ['model' => 'gpt-4o-2024', 'choices' => [], 'usage' => [
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
            'total_tokens' => 30,
            'prompt_tokens_details' => ['cached_tokens' => 8],
            'completion_tokens_details' => ['reasoning_tokens' => 3],
        ]],
    ]);

    $finishes = array_values(array_filter($events, fn($e) => $e instanceof Finish));

    expect($finishes)->toHaveCount(1)
        ->and($events[array_key_last($events)])->toBe($finishes[0])
        ->and($finishes[0]->reason)->toBe(ProviderFinishReason::ToolUse)
        ->and($finishes[0]->usage)->toEqual(new Usage(20, 10, 30, cacheReadTokens: 8, reasoningTokens: 3));
});

test('streamEvents finishes on the finish chunk when it carries usage itself', function () {
    $events = openAIStreamEvents([
        ['choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7]],
    ]);

    expect(openAIEventClasses($events))->toBe([TextDelta::class, Finish::class])
        ->and($events[1]->reason)->toBe(ProviderFinishReason::Stop)
        ->and($events[1]->usage)->toEqual(new Usage(5, 2, 7));
});

test('streamEvents finishes with null usage when no usage chunk arrives', function (bool $done) {
    $events = openAIStreamEvents([
        ['choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => null]]],
        openAIFinishChunk('stop'),
    ], $done);

    expect(openAIEventClasses($events))->toBe([TextDelta::class, Finish::class])
        ->and($events[1]->reason)->toBe(ProviderFinishReason::Stop)
        ->and($events[1]->usage)->toBeNull();
})->with(['at [DONE]' => [true], 'at EOF' => [false]]);

test('streamEvents totals prompt and completion when the provider sends no total', function () {
    $events = openAIStreamEvents([
        openAIFinishChunk('stop'),
        ['choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]],
    ]);

    expect($events[array_key_last($events)]->usage)->toEqual(new Usage(5, 2, 7));
});

test('streamEvents yields Finish(Error) when the stream ends without a finish_reason', function () {
    $events = openAIStreamEvents([
        ['model' => 'gpt-4o-2024', 'choices' => [['delta' => ['content' => 'Half'], 'finish_reason' => null]]],
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => '{}']]]),
    ]);

    expect(openAIEventClasses($events))->toBe([TextDelta::class, ToolInputStart::class, ToolInputDelta::class, Finish::class])
        ->and($events[3]->reason)->toBe(ProviderFinishReason::Error)
        ->and($events[3]->rawReason)->toBe('')
        ->and($events[3]->model)->toBe('gpt-4o-2024');
});

test('streamEvents yields reasoning deltas from every reasoning field', function (string $field) {
    $events = openAIStreamEvents([
        ['choices' => [['delta' => [$field => 'thinking'], 'finish_reason' => null]]],
        ['choices' => [['delta' => ['content' => 'Answer'], 'finish_reason' => 'stop']]],
    ]);

    expect($events)->toEqual([
        new ReasoningDelta('thinking'),
        new TextDelta('Answer'),
        new Finish(ProviderFinishReason::Stop, 'stop', null, 'gpt-4o'),
    ]);
})->with(['reasoning', 'reasoning_content', 'thinking']);

test('streamEvents streams a refusal as text and finishes with Refusal, dropping tool calls', function (string $finishReason) {
    $events = openAIStreamEvents([
        ['choices' => [['delta' => ['refusal' => 'I cannot help.'], 'finish_reason' => null]]],
        openAIToolChunk([['index' => 0, 'id' => 'c1', 'function' => ['name' => 'x', 'arguments' => '{}']]]),
        openAIFinishChunk($finishReason),
    ]);

    $finish = $events[array_key_last($events)];

    expect($events[0])->toEqual(new TextDelta('I cannot help.'))
        ->and(array_filter($events, fn($e) => $e instanceof ToolInputEnd))->toBe([])
        ->and($finish->reason)->toBe(ProviderFinishReason::Refusal)
        ->and($finish->rawReason)->toBe($finishReason);
})->with(['stop', 'tool_calls']);

test('streamEvents finishes with Stop when tool_calls arrives without any tool call', function () {
    $events = openAIStreamEvents([
        ['choices' => [['delta' => ['reasoning' => 'pondering'], 'finish_reason' => null]]],
        openAIFinishChunk('tool_calls'),
    ]);

    $finish = $events[array_key_last($events)];

    expect($finish->reason)->toBe(ProviderFinishReason::Stop)
        ->and($finish->rawReason)->toBe('tool_calls');
});

test('streamEvents yields a Tick during a silent gap', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, openAIEventsBody([['choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => null]]]], false)), ScriptedStreamClient::TIMEOUT],
        [ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, openAIEventsBody([openAIFinishChunk('stop')])), new LastChunk()],
    ]);
    $provider = (new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client))
        ->withRequestLimits(new RequestLimits(idleTimeout: 5.0, tickInterval: 0.05));

    $events = array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));

    expect(openAIEventClasses($events))->toBe([TextDelta::class, Tick::class, Tick::class, Finish::class])
        ->and($client->timeouts[0])->toBe(0.05)
        ->and($events[3]->reason)->toBe(ProviderFinishReason::Stop);
});

test('streamEvents cancels the HTTP response when the consumer stops iterating', function () {
    // MockHttpClient answers with a copy of the MockResponse, so keep the one request() returns.
    $client = new class (new MockHttpClient([new MockResponse(openAIEventsBody([
        ['choices' => [['delta' => ['content' => 'a'], 'finish_reason' => null]]],
        ['choices' => [['delta' => ['content' => 'b'], 'finish_reason' => null]]],
        openAIFinishChunk('stop'),
    ]), ['http_code' => 200])])) implements HttpClientInterface {
        use DecoratorTrait;

        public ?ResponseInterface $response = null;

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->response = $this->client->request($method, $url, $options);
        }
    };
    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    $events = $provider->streamEvents([new UserMessage('hi')]);
    foreach ($events as $event) {
        break;
    }

    expect($client->response?->getInfo('canceled'))->not->toBeTrue();

    unset($events, $event);

    expect($client->response?->getInfo('canceled'))->toBeTrue();
});

test('chat normalises usage the same way as streaming', function () {
    $client = new MockHttpClient([new MockResponse(json_encode([
        'model' => 'gpt-4o',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
        'usage' => [
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
            'total_tokens' => 30,
            'prompt_tokens_details' => ['cached_tokens' => 8],
            'completion_tokens_details' => ['reasoning_tokens' => 3],
        ],
    ]), ['http_code' => 200])]);
    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    expect($provider->chat([new UserMessage('hi')])->usage)
        ->toEqual(new Usage(20, 10, 30, cacheReadTokens: 8, reasoningTokens: 3));
});
