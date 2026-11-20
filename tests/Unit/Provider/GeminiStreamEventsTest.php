<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
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
function geminiEventsBody(array $chunks): string
{
    return implode('', array_map(fn(array $c) => 'data: ' . json_encode($c) . "\n\n", $chunks));
}

/**
 * @param list<array<string, mixed>> $chunks
 * @return list<object>
 */
function geminiStreamEvents(array $chunks): array
{
    $client = new MockHttpClient([new MockResponse(geminiEventsBody($chunks), ['http_code' => 200])]);
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'key', httpClient: $client);

    return array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));
}

/**
 * @param list<object> $events
 * @return list<class-string>
 */
function geminiEventClasses(array $events): array
{
    return array_map(fn(object $e) => $e::class, $events);
}

/**
 * @param list<array<string, mixed>> $parts
 * @return array<string, mixed>
 */
function geminiChunk(array $parts, ?string $finishReason = null, array $extra = []): array
{
    $candidate = ['content' => ['role' => 'model', 'parts' => $parts]];
    if ($finishReason !== null) {
        $candidate['finishReason'] = $finishReason;
    }

    return ['candidates' => [$candidate], ...$extra];
}

test('streamEvents yields text deltas then one Finish with the raw finish reason', function () {
    $events = geminiStreamEvents([
        geminiChunk([['text' => 'Hel']]),
        geminiChunk([['text' => 'lo']], 'STOP'),
    ]);

    expect($events)->toEqual([
        new TextDelta('Hel'),
        new TextDelta('lo'),
        new Finish(ProviderFinishReason::Stop, 'STOP', null, 'gemini-2.5-flash'),
    ]);
});

test('streamEvents yields thought parts as reasoning deltas', function () {
    $events = geminiStreamEvents([
        geminiChunk([['text' => 'Pondering', 'thought' => true]]),
        geminiChunk([['text' => 'Answer']], 'STOP'),
    ]);

    expect(geminiEventClasses($events))->toBe([ReasoningDelta::class, TextDelta::class, Finish::class])
        ->and($events[0])->toEqual(new ReasoningDelta('Pondering'));
});

test('streamEvents synthesises start, one full delta and an end for each whole functionCall part', function () {
    $events = geminiStreamEvents([
        geminiChunk([
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'NYC']], 'thoughtSignature' => 'sig-1'],
            ['functionCall' => ['name' => 'list_files', 'args' => []]],
        ], 'STOP'),
    ]);

    expect($events)->toEqual([
        new ToolInputStart(0, 'g00000000', 'get_weather'),
        new ToolInputDelta(0, 'g00000000', '{"city":"NYC"}'),
        new ToolInputEnd(0, 'g00000000', 'get_weather', ['city' => 'NYC'], ['thoughtSignature' => 'sig-1']),
        new ToolInputStart(1, 'g00000001', 'list_files'),
        new ToolInputDelta(1, 'g00000001', '{}'),
        new ToolInputEnd(1, 'g00000001', 'list_files', []),
        new Finish(ProviderFinishReason::ToolUse, 'STOP', null, 'gemini-2.5-flash'),
    ]);
});

test('streamEvents sends an empty object for a functionCall with no args', function () {
    $events = geminiStreamEvents([
        geminiChunk([['functionCall' => ['name' => 'ping']]], 'STOP'),
    ]);

    expect($events[1])->toEqual(new ToolInputDelta(0, 'g00000000', '{}'))
        ->and($events[2])->toEqual(new ToolInputEnd(0, 'g00000000', 'ping', []));
});

test('streamEvents keeps part order: text before a tool call in the same chunk comes first', function () {
    $events = geminiStreamEvents([
        geminiChunk([['text' => 'Hi'], ['functionCall' => ['name' => 'x', 'args' => ['a' => 1]]], ['text' => ' there']], 'STOP'),
    ]);

    expect(geminiEventClasses($events))->toBe([
        TextDelta::class,
        ToolInputStart::class,
        ToolInputDelta::class,
        ToolInputEnd::class,
        TextDelta::class,
        Finish::class,
    ])
        ->and($events[0])->toEqual(new TextDelta('Hi'))
        ->and($events[4])->toEqual(new TextDelta(' there'));
});

test('streamEvents ends calls from an earlier chunk at once and finishes ToolUse on a later STOP', function () {
    $events = geminiStreamEvents([
        geminiChunk([['functionCall' => ['name' => 'x', 'args' => ['a' => 1]]]]),
        geminiChunk([['text' => '']], 'STOP'),
    ]);

    expect(geminiEventClasses($events))->toBe([ToolInputStart::class, ToolInputDelta::class, ToolInputEnd::class, Finish::class])
        ->and($events[3]->reason)->toBe(ProviderFinishReason::ToolUse);
});

test('streamEvents emits no tool events when the finish reason drops tool calls', function (string $raw, ProviderFinishReason $reason) {
    $events = geminiStreamEvents([
        geminiChunk([['text' => 'Part'], ['functionCall' => ['name' => 'x', 'args' => ['a' => 1]]]], $raw),
    ]);

    expect($events)->toEqual([
        new TextDelta('Part'),
        new Finish($reason, $raw, null, 'gemini-2.5-flash'),
    ]);
})->with([
    'MAX_TOKENS' => ['MAX_TOKENS', ProviderFinishReason::MaxTokens],
    'SAFETY' => ['SAFETY', ProviderFinishReason::ContentFilter],
    'MALFORMED_FUNCTION_CALL' => ['MALFORMED_FUNCTION_CALL', ProviderFinishReason::Error],
]);

test('streamEvents drops a call whose args are not a JSON object and finishes with Error', function () {
    $events = geminiStreamEvents([
        geminiChunk([['functionCall' => ['name' => 'x', 'args' => [1, 2]]]], 'STOP'),
    ]);

    expect(geminiEventClasses($events))->toBe([ToolInputStart::class, ToolInputDelta::class, Finish::class])
        ->and($events[2])->toEqual(new Finish(ProviderFinishReason::Error, 'STOP', null, 'gemini-2.5-flash'));
});

test('streamEvents drops a call whose args nest past the depth cap and finishes with Error', function () {
    $events = geminiStreamEvents([
        geminiChunk([['functionCall' => ['name' => 'x', 'args' => json_decode(str_repeat('{"a":', 65) . '1' . str_repeat('}', 65), true)]]], 'STOP'),
    ]);

    expect(geminiEventClasses($events))->toBe([ToolInputStart::class, ToolInputDelta::class, Finish::class])
        ->and($events[2])->toEqual(new Finish(ProviderFinishReason::Error, 'STOP', null, 'gemini-2.5-flash'));
});

test('streamEvents maps a blocked prompt to ContentFilter with the block reason as raw reason', function () {
    $events = geminiStreamEvents([
        ['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 3, 'totalTokenCount' => 3]],
    ]);

    expect($events)->toEqual([
        new Finish(ProviderFinishReason::ContentFilter, 'SAFETY', new Usage(3, 0, 3), 'gemini-2.5-flash'),
    ]);
});

test('streamEvents folds post-finish usage-only chunks into the single Finish at EOF', function () {
    $events = geminiStreamEvents([
        geminiChunk([['text' => 'Done.']], 'STOP', ['usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 1, 'totalTokenCount' => 8]]),
        geminiChunk([['text' => 'Late text'], ['functionCall' => ['name' => 'x', 'args' => []]]], 'STOP'),
        ['usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 2, 'totalTokenCount' => 9]],
    ]);

    expect($events)->toEqual([
        new TextDelta('Done.'),
        new Finish(ProviderFinishReason::Stop, 'STOP', new Usage(7, 2, 9), 'gemini-2.5-flash'),
    ]);
});

test('streamEvents counts thought tokens as completion and reads cached tokens', function () {
    $events = geminiStreamEvents([
        geminiChunk([['text' => 'Hi']], 'STOP', ['usageMetadata' => [
            'promptTokenCount' => 20,
            'candidatesTokenCount' => 5,
            'thoughtsTokenCount' => 4,
            'cachedContentTokenCount' => 6,
            'totalTokenCount' => 29,
        ]]),
    ]);

    expect($events[1]->usage)->toEqual(new Usage(20, 9, 29, 6, null, 4));
});

test('streamEvents yields Finish(Error) when the stream ends without a finish reason', function () {
    $events = geminiStreamEvents([
        geminiChunk([['text' => 'Half an ans']]),
    ]);

    expect($events)->toEqual([
        new TextDelta('Half an ans'),
        new Finish(ProviderFinishReason::Error, '', null, 'gemini-2.5-flash'),
    ]);
});

test('streamEvents yields a Tick during a silent gap', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, geminiEventsBody([geminiChunk([['text' => 'Hi']])])), ScriptedStreamClient::TIMEOUT],
        [ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, geminiEventsBody([geminiChunk([['text' => '!']], 'STOP')])), new LastChunk()],
    ]);
    $provider = (new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'key', httpClient: $client))
        ->withRequestLimits(new RequestLimits(idleTimeout: 5.0, tickInterval: 0.05));

    $events = array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));

    expect(geminiEventClasses($events))->toBe([TextDelta::class, Tick::class, Tick::class, TextDelta::class, Finish::class])
        ->and($client->timeouts[0])->toBe(0.05)
        ->and($events[4]->reason)->toBe(ProviderFinishReason::Stop);
});

test('streamEvents cancels the HTTP response when the consumer stops iterating', function () {
    // MockHttpClient answers with a copy of the MockResponse, so keep the one request() returns.
    $client = new class (new MockHttpClient([new MockResponse(geminiEventsBody([
        geminiChunk([['text' => 'a']]),
        geminiChunk([['text' => 'b']], 'STOP'),
    ]), ['http_code' => 200])])) implements HttpClientInterface {
        use DecoratorTrait;

        public ?ResponseInterface $response = null;

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->response = $this->client->request($method, $url, $options);
        }
    };
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'key', httpClient: $client);

    $events = $provider->streamEvents([new UserMessage('hi')]);
    foreach ($events as $event) {
        break;
    }

    expect($client->response?->getInfo('canceled'))->not->toBeTrue();

    unset($events, $event);

    expect($client->response?->getInfo('canceled'))->toBeTrue();
});

test('stream projects deltas then exactly one terminal chunk with usage and tool calls', function () {
    $client = new MockHttpClient([new MockResponse(geminiEventsBody([
        geminiChunk([['text' => 'Calling.'], ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'NYC']]]]),
        geminiChunk([], 'STOP', ['usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]]),
    ]), ['http_code' => 200])]);
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'key', httpClient: $client);

    $chunks = array_values(iterator_to_array($provider->stream([new UserMessage('hi')]), false));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('Calling.')
        ->and($chunks[0]->usage)->toBeNull()
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($chunks[1]->usage)->toEqual(new Usage(5, 2, 7))
        ->and($chunks[1]->toolCalls)->toHaveCount(1)
        ->and($chunks[1]->toolCalls[0]->id)->toBe('g00000000')
        ->and($chunks[1]->toolCalls[0]->arguments)->toBe(['city' => 'NYC']);
});

test('chat normalises usage the same way as streaming', function () {
    $client = new MockHttpClient([new MockResponse(json_encode([
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Hi']]], 'finishReason' => 'STOP']],
        'usageMetadata' => [
            'promptTokenCount' => 20,
            'candidatesTokenCount' => 5,
            'thoughtsTokenCount' => 4,
            'cachedContentTokenCount' => 6,
            'totalTokenCount' => 29,
        ],
    ]), ['http_code' => 200])]);
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'key', httpClient: $client);

    expect($provider->chat([new UserMessage('hi')])->usage)->toEqual(new Usage(20, 9, 29, 6, null, 4));
});
