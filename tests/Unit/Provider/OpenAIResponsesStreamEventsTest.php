<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
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
 * @param list<array<string, mixed>> $events
 */
function responsesEventsBody(array $events): string
{
    return implode('', array_map(fn(array $e) => 'data: ' . json_encode($e) . "\n\n", $events));
}

/**
 * @param list<array<string, mixed>> $events
 * @return list<object>
 */
function responsesStreamEvents(array $events): array
{
    $client = new MockHttpClient([new MockResponse(responsesEventsBody($events), ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    return array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));
}

/**
 * @param list<object> $events
 * @return list<class-string>
 */
function responsesEventClasses(array $events): array
{
    return array_map(fn(object $e) => $e::class, $events);
}

/**
 * @return array<string, mixed>
 */
function responsesCallAdded(int $outputIndex, string $callId, string $name): array
{
    return ['type' => 'response.output_item.added', 'output_index' => $outputIndex, 'item' => ['type' => 'function_call', 'call_id' => $callId, 'name' => $name, 'arguments' => '']];
}

/**
 * @return array<string, mixed>
 */
function responsesArgsDelta(int $outputIndex, string $delta): array
{
    return ['type' => 'response.function_call_arguments.delta', 'output_index' => $outputIndex, 'delta' => $delta];
}

/**
 * @return array<string, mixed>
 */
function responsesCallDone(int $outputIndex, string $callId, string $name, mixed $arguments): array
{
    return ['type' => 'response.output_item.done', 'output_index' => $outputIndex, 'item' => ['type' => 'function_call', 'call_id' => $callId, 'name' => $name, 'arguments' => $arguments]];
}

/**
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function responsesCompleted(array $response = []): array
{
    return ['type' => 'response.completed', 'response' => ['model' => 'gpt-4o-2024', 'status' => 'completed', ...$response]];
}

test('streamEvents yields text deltas then one Finish with the completed usage and model', function () {
    $events = responsesStreamEvents([
        ['type' => 'response.created', 'response' => ['model' => 'gpt-4o-2024', 'status' => 'in_progress', 'usage' => null]],
        ['type' => 'response.output_text.delta', 'delta' => 'He'],
        ['type' => 'response.output_text.delta', 'delta' => 'llo'],
        responsesCompleted(['usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7]]),
    ]);

    expect($events)->toEqual([
        new TextDelta('He'),
        new TextDelta('llo'),
        new Finish(ProviderFinishReason::Stop, 'completed', new Usage(5, 2, 7), 'gpt-4o-2024'),
    ]);
});

test('streamEvents yields no Finish from the usage on response.created', function () {
    $events = responsesStreamEvents([
        ['type' => 'response.created', 'response' => ['model' => 'gpt-4o-2024', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
        ['type' => 'response.output_text.delta', 'delta' => 'Hi'],
        responsesCompleted(['usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7]]),
    ]);

    $finishes = array_values(array_filter($events, fn($e) => $e instanceof Finish));

    expect(responsesEventClasses($events))->toBe([TextDelta::class, Finish::class])
        ->and($finishes[0]->usage)->toEqual(new Usage(5, 2, 7));
});

test('streamEvents yields reasoning summary deltas and refusal text', function () {
    $events = responsesStreamEvents([
        ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'thinking'],
        ['type' => 'response.refusal.delta', 'delta' => 'I cannot help.'],
        responsesCompleted(),
    ]);

    expect($events)->toEqual([
        new ReasoningDelta('thinking'),
        new TextDelta('I cannot help.'),
        new Finish(ProviderFinishReason::Refusal, 'completed', null, 'gpt-4o-2024'),
    ]);
});

test('streamEvents yields start, deltas with the call id repeated, and an end when the item is done', function () {
    $events = responsesStreamEvents([
        responsesCallAdded(0, 'call_1', 'get_weather'),
        responsesArgsDelta(0, '{"city"'),
        responsesArgsDelta(0, ':"NYC"}'),
        responsesCallDone(0, 'call_1', 'get_weather', '{"city":"NYC"}'),
        responsesCompleted(),
    ]);

    expect($events)->toEqual([
        new ToolInputStart(0, 'call_1', 'get_weather'),
        new ToolInputDelta(0, 'call_1', '{"city"'),
        new ToolInputDelta(0, 'call_1', ':"NYC"}'),
        new ToolInputEnd(0, 'call_1', 'get_weather', ['city' => 'NYC']),
        new Finish(ProviderFinishReason::ToolUse, 'completed', null, 'gpt-4o-2024'),
    ]);
});

test('streamEvents indexes calls by the order they opened, not by output_index', function () {
    $events = responsesStreamEvents([
        ['type' => 'response.output_text.delta', 'delta' => 'Calling.'],
        responsesCallAdded(2, 'call_a', 'first'),
        responsesCallAdded(1, 'call_b', 'second'),
        responsesArgsDelta(1, '{"b":2}'),
        responsesArgsDelta(2, '{"a":1}'),
        responsesCallDone(1, 'call_b', 'second', '{"b":2}'),
        responsesCallDone(2, 'call_a', 'first', '{"a":1}'),
        responsesCompleted(),
    ]);

    $toolEvents = array_values(array_filter($events, fn($e) => !$e instanceof TextDelta && !$e instanceof Finish));

    expect($toolEvents)->toEqual([
        new ToolInputStart(0, 'call_a', 'first'),
        new ToolInputStart(1, 'call_b', 'second'),
        new ToolInputDelta(1, 'call_b', '{"b":2}'),
        new ToolInputDelta(0, 'call_a', '{"a":1}'),
        new ToolInputEnd(1, 'call_b', 'second', ['b' => 2]),
        new ToolInputEnd(0, 'call_a', 'first', ['a' => 1]),
    ]);
});

test('streamEvents yields the done item arguments as one delta when none streamed', function () {
    $events = responsesStreamEvents([
        responsesCallAdded(0, 'call_1', 'get_weather'),
        responsesCallDone(0, 'call_1', 'get_weather', '{"city":"NYC"}'),
        responsesCompleted(),
    ]);

    expect(array_slice($events, 0, 3))->toEqual([
        new ToolInputStart(0, 'call_1', 'get_weather'),
        new ToolInputDelta(0, 'call_1', '{"city":"NYC"}'),
        new ToolInputEnd(0, 'call_1', 'get_weather', ['city' => 'NYC']),
    ]);
});

test('streamEvents opens a call from a done item that was never added', function () {
    $events = responsesStreamEvents([
        responsesCallDone(0, 'call_1', 'get_weather', '{"city":"NYC"}'),
        responsesCompleted(),
    ]);

    expect($events)->toEqual([
        new ToolInputStart(0, 'call_1', 'get_weather'),
        new ToolInputDelta(0, 'call_1', '{"city":"NYC"}'),
        new ToolInputEnd(0, 'call_1', 'get_weather', ['city' => 'NYC']),
        new Finish(ProviderFinishReason::ToolUse, 'completed', null, 'gpt-4o-2024'),
    ]);
});

test('streamEvents decodes empty, whitespace or object-shaped empty arguments as an empty object', function (mixed $arguments) {
    $events = responsesStreamEvents([
        responsesCallAdded(0, 'call_1', 'now'),
        responsesCallDone(0, 'call_1', 'now', $arguments),
        responsesCompleted(),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));
    $finish = $events[array_key_last($events)];

    expect($ends)->toEqual([new ToolInputEnd(0, 'call_1', 'now', [])])
        ->and($finish->reason)->toBe(ProviderFinishReason::ToolUse);
})->with(['empty' => [''], 'whitespace' => ['  '], 'empty object' => [new \stdClass()]]);

test('streamEvents accepts done item arguments sent as a JSON object', function () {
    $events = responsesStreamEvents([
        responsesCallAdded(0, 'call_1', 'get_weather'),
        responsesCallDone(0, 'call_1', 'get_weather', ['city' => 'NYC']),
        responsesCompleted(),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));

    expect($ends)->toEqual([new ToolInputEnd(0, 'call_1', 'get_weather', ['city' => 'NYC'])]);
});

test('streamEvents drops a call whose arguments are not a JSON object and finishes with Error', function (string $arguments) {
    $events = responsesStreamEvents([
        responsesCallAdded(0, 'call_1', 'x'),
        responsesArgsDelta(0, $arguments),
        responsesCallDone(0, 'call_1', 'x', $arguments),
        responsesCallAdded(1, 'call_2', 'y'),
        responsesArgsDelta(1, '{"ok":true}'),
        responsesCallDone(1, 'call_2', 'y', '{"ok":true}'),
        responsesCompleted(),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));
    $finish = $events[array_key_last($events)];

    expect($ends)->toEqual([new ToolInputEnd(1, 'call_2', 'y', ['ok' => true])])
        ->and($finish)->toBeInstanceOf(Finish::class)
        ->and($finish->reason)->toBe(ProviderFinishReason::Error)
        ->and($finish->rawReason)->toBe('completed');
})->with(['truncated' => ['{"a":'], 'array' => ['[1,2]'], 'scalar' => ['42']]);

test('streamEvents ends a call that never got its done item when the response completes', function () {
    $events = responsesStreamEvents([
        responsesCallAdded(0, 'call_1', 'get_weather'),
        responsesArgsDelta(0, '{"city":"NYC"}'),
        responsesCompleted(),
    ]);

    expect(array_slice($events, 2))->toEqual([
        new ToolInputEnd(0, 'call_1', 'get_weather', ['city' => 'NYC']),
        new Finish(ProviderFinishReason::ToolUse, 'completed', null, 'gpt-4o-2024'),
    ]);
});

test('streamEvents finishes with Refusal when a refusal streams alongside tool calls', function () {
    $events = responsesStreamEvents([
        ['type' => 'response.refusal.delta', 'delta' => 'I cannot help.'],
        responsesCallAdded(1, 'call_1', 'get_weather'),
        responsesCallDone(1, 'call_1', 'get_weather', '{"city":"NYC"}'),
        responsesCompleted(),
    ]);

    // The call's end is emitted when its item is done; the Refusal finish means it never runs.
    expect($events)->toEqual([
        new TextDelta('I cannot help.'),
        new ToolInputStart(0, 'call_1', 'get_weather'),
        new ToolInputDelta(0, 'call_1', '{"city":"NYC"}'),
        new ToolInputEnd(0, 'call_1', 'get_weather', ['city' => 'NYC']),
        new Finish(ProviderFinishReason::Refusal, 'completed', null, 'gpt-4o-2024'),
    ]);
});

test('stream projects a refusal with tool calls as one Refusal chunk with no tool calls', function () {
    $client = new MockHttpClient([new MockResponse(responsesEventsBody([
        ['type' => 'response.refusal.delta', 'delta' => 'I cannot help.'],
        responsesCallAdded(1, 'call_1', 'get_weather'),
        responsesCallDone(1, 'call_1', 'get_weather', '{"city":"NYC"}'),
        responsesCompleted(),
    ]), ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    $chunks = array_values(iterator_to_array($provider->stream([new UserMessage('hi')]), false));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('I cannot help.')
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::Refusal)
        ->and($chunks[1]->toolCalls)->toBe([]);
});

test('streamEvents takes the call id and name from the done item when the added item lacked them', function () {
    $events = responsesStreamEvents([
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'arguments' => '']],
        responsesArgsDelta(0, '{"city":"NYC"}'),
        responsesCallDone(0, 'call_1', 'get_weather', '{"city":"NYC"}'),
        responsesCompleted(),
    ]);

    $ends = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));

    expect($ends)->toEqual([new ToolInputEnd(0, 'call_1', 'get_weather', ['city' => 'NYC'])]);
});

test('streamEvents reads cached and reasoning tokens and keeps the provider total', function () {
    $events = responsesStreamEvents([
        responsesCompleted(['usage' => [
            'input_tokens' => 20,
            'input_tokens_details' => ['cached_tokens' => 8],
            'output_tokens' => 10,
            'output_tokens_details' => ['reasoning_tokens' => 3],
            'total_tokens' => 31,
        ]]),
    ]);

    expect($events)->toEqual([
        new Finish(ProviderFinishReason::Stop, 'completed', new Usage(20, 10, 31, cacheReadTokens: 8, reasoningTokens: 3), 'gpt-4o-2024'),
    ]);
});

test('streamEvents totals input and output when the provider sends no total', function () {
    $events = responsesStreamEvents([
        responsesCompleted(['usage' => ['input_tokens' => 5, 'output_tokens' => 2]]),
    ]);

    expect($events[0]->usage)->toEqual(new Usage(5, 2, 7));
});

test('streamEvents maps an incomplete or failed response with its raw reason', function (array $response, ProviderFinishReason $reason, string $rawReason) {
    $events = responsesStreamEvents([
        responsesCallAdded(0, 'call_1', 'get_weather'),
        responsesArgsDelta(0, '{"ci'),
        ['type' => $response['status'] === 'failed' ? 'response.failed' : 'response.incomplete', 'response' => [
            'model' => 'gpt-4o-2024',
            ...$response,
            'usage' => ['input_tokens' => 4, 'output_tokens' => 2, 'total_tokens' => 6],
        ]],
    ]);

    expect(array_filter($events, fn($e) => $e instanceof ToolInputEnd))->toBe([])
        ->and($events[array_key_last($events)])->toEqual(new Finish($reason, $rawReason, new Usage(4, 2, 6), 'gpt-4o-2024'));
})->with([
    'max_output_tokens' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']], ProviderFinishReason::MaxTokens, 'max_output_tokens'],
    'content_filter' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter']], ProviderFinishReason::ContentFilter, 'content_filter'],
    'incomplete without a reason' => [['status' => 'incomplete'], ProviderFinishReason::MaxTokens, 'incomplete'],
    'failed without a message' => [['status' => 'failed', 'error' => null], ProviderFinishReason::Error, 'failed'],
]);

test('streamEvents throws when a failed response carries an error message', function () {
    responsesStreamEvents([
        ['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'The server had an error.']]],
    ]);
})->throws(ProviderException::class, 'server_error: The server had an error.');

test('streamEvents throws a generic error type when an error code is not a string', function (string $type) {
    responsesStreamEvents(match ($type) {
        'error' => [['type' => 'error', 'code' => ['nested' => true], 'message' => 'Rate limit reached.']],
        default => [['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => ['code' => ['nested' => true], 'message' => 'Rate limit reached.']]]],
    });
})->with(['error event' => ['error'], 'failed response' => ['failed']])
    ->throws(ProviderException::class, 'error: Rate limit reached.');

test('streamEvents yields Finish(Error) when the stream ends without a terminal event', function () {
    $events = responsesStreamEvents([
        ['type' => 'response.created', 'response' => ['model' => 'gpt-4o-2024']],
        responsesCallAdded(0, 'call_1', 'get_weather'),
        responsesArgsDelta(0, '{"city":"NYC"}'),
        ['type' => 'response.output_text.delta', 'delta' => 'Half'],
    ]);

    expect(responsesEventClasses($events))->toBe([ToolInputStart::class, ToolInputDelta::class, TextDelta::class, Finish::class])
        ->and($events[3])->toEqual(new Finish(ProviderFinishReason::Error, '', null, 'gpt-4o-2024'));
});

test('streamEvents yields a Tick during a silent gap', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, responsesEventsBody([['type' => 'response.output_text.delta', 'delta' => 'Hi']])), ScriptedStreamClient::TIMEOUT],
        [ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, responsesEventsBody([responsesCompleted()])), new LastChunk()],
    ]);
    $provider = (new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client))
        ->withRequestLimits(new RequestLimits(idleTimeout: 5.0, tickInterval: 0.05));

    $events = array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));

    expect(responsesEventClasses($events))->toBe([TextDelta::class, Tick::class, Tick::class, Finish::class])
        ->and($client->timeouts[0])->toBe(0.05)
        ->and($events[3]->reason)->toBe(ProviderFinishReason::Stop);
});

test('streamEvents cancels the HTTP response when the consumer stops iterating', function () {
    // MockHttpClient answers with a copy of the MockResponse, so keep the one request() returns.
    $client = new class (new MockHttpClient([new MockResponse(responsesEventsBody([
        ['type' => 'response.output_text.delta', 'delta' => 'a'],
        ['type' => 'response.output_text.delta', 'delta' => 'b'],
        responsesCompleted(),
    ]), ['http_code' => 200])])) implements HttpClientInterface {
        use DecoratorTrait;

        public ?ResponseInterface $response = null;

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->response = $this->client->request($method, $url, $options);
        }
    };
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    $events = $provider->streamEvents([new UserMessage('hi')]);
    foreach ($events as $event) {
        break;
    }

    expect($client->response?->getInfo('canceled'))->not->toBeTrue();

    unset($events, $event);

    expect($client->response?->getInfo('canceled'))->toBeTrue();
});

test('stream projects deltas then exactly one terminal chunk with usage and tool calls', function () {
    $client = new MockHttpClient([new MockResponse(responsesEventsBody([
        ['type' => 'response.created', 'response' => ['model' => 'gpt-4o-2024', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
        ['type' => 'response.output_text.delta', 'delta' => 'Calling.'],
        responsesCallAdded(1, 'call_1', 'get_weather'),
        responsesArgsDelta(1, '{"city":"NYC"}'),
        responsesCallDone(1, 'call_1', 'get_weather', '{"city":"NYC"}'),
        responsesCompleted(['usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7]]),
    ]), ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    $chunks = array_values(iterator_to_array($provider->stream([new UserMessage('hi')]), false));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('Calling.')
        ->and($chunks[0]->usage)->toBeNull()
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($chunks[1]->model)->toBe('gpt-4o-2024')
        ->and($chunks[1]->usage)->toEqual(new Usage(5, 2, 7))
        ->and($chunks[1]->toolCalls)->toHaveCount(1)
        ->and($chunks[1]->toolCalls[0]->arguments)->toBe(['city' => 'NYC']);
});

test('chat normalises usage the same way as streaming', function () {
    $client = new MockHttpClient([new MockResponse(json_encode([
        'model' => 'gpt-4o',
        'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi']]]],
        'usage' => [
            'input_tokens' => 20,
            'input_tokens_details' => ['cached_tokens' => 8],
            'output_tokens' => 10,
            'output_tokens_details' => ['reasoning_tokens' => 3],
            'total_tokens' => 31,
        ],
    ]), ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    expect($provider->chat([new UserMessage('hi')])->usage)
        ->toEqual(new Usage(20, 10, 31, cacheReadTokens: 8, reasoningTokens: 3));
});
