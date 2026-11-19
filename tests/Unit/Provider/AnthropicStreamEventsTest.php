<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
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
 * @param array<string, mixed> ...$events
 */
function anthropicEventsBody(array ...$events): string
{
    $body = '';
    foreach ($events as $event) {
        $body .= 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event) . "\n\n";
    }

    return $body;
}

/**
 * @param array<string, mixed> ...$events
 * @return list<object>
 */
function anthropicStreamEvents(array ...$events): array
{
    $client = new MockHttpClient([new MockResponse(anthropicEventsBody(...$events), ['http_code' => 200])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $client);

    return array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));
}

/**
 * @param list<object> $events
 * @return list<class-string>
 */
function anthropicEventClasses(array $events): array
{
    return array_map(fn(object $e) => $e::class, $events);
}

/**
 * @return array<string, mixed>
 */
function anthropicEvStart(array $usage = ['input_tokens' => 12, 'output_tokens' => 1]): array
{
    return ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => $usage]];
}

/**
 * @return array<string, mixed>
 */
function anthropicEvStop(?string $stopReason, int $outputTokens = 3): array
{
    return ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => $outputTokens]];
}

/**
 * @return array<string, mixed>
 */
function anthropicEvTool(int $index, string $id, string $name): array
{
    return ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => []]];
}

/**
 * @return array<string, mixed>
 */
function anthropicEvJson(int $index, string $json): array
{
    return ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $json]];
}

/**
 * @return array<string, mixed>
 */
function anthropicEvBlockStop(int $index): array
{
    return ['type' => 'content_block_stop', 'index' => $index];
}

const ANTHROPIC_EV_MESSAGE_STOP = ['type' => 'message_stop'];

test('streamEvents maps thinking and text deltas to ReasoningDelta and TextDelta', function () {
    $events = anthropicStreamEvents(
        anthropicEvStart(),
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Hmm']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']],
        anthropicEvBlockStop(0),
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => ' there']],
        anthropicEvBlockStop(1),
        anthropicEvStop('end_turn'),
        ANTHROPIC_EV_MESSAGE_STOP,
    );

    expect(anthropicEventClasses($events))->toBe([ReasoningDelta::class, TextDelta::class, TextDelta::class, Finish::class])
        ->and($events[0]->delta)->toBe('Hmm')
        ->and($events[1]->delta)->toBe('Hi')
        ->and($events[2]->delta)->toBe(' there')
        ->and($events[3]->reason)->toBe(ProviderFinishReason::Stop)
        ->and($events[3]->rawReason)->toBe('end_turn')
        ->and($events[3]->model)->toBe('claude-test')
        ->and($events[3]->contentBlocks)->toBe([
            ['type' => 'thinking', 'thinking' => 'Hmm', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Hi there'],
        ]);
});

test('streamEvents emits tool input events in tool order and ends each call at its block stop', function () {
    $events = anthropicStreamEvents(
        anthropicEvStart(),
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Looking']],
        anthropicEvBlockStop(0),
        anthropicEvTool(1, 'toolu_a', 'lookup'),
        anthropicEvJson(1, '{"q":'),
        anthropicEvJson(1, '"php"}'),
        anthropicEvBlockStop(1),
        anthropicEvTool(2, 'toolu_b', 'now'),
        anthropicEvBlockStop(2),
        anthropicEvStop('tool_use'),
        ANTHROPIC_EV_MESSAGE_STOP,
    );

    expect(anthropicEventClasses($events))->toBe([
        TextDelta::class,
        ToolInputStart::class, ToolInputDelta::class, ToolInputDelta::class, ToolInputEnd::class,
        ToolInputStart::class, ToolInputEnd::class,
        Finish::class,
    ])
        ->and($events[1])->toEqual(new ToolInputStart(0, 'toolu_a', 'lookup'))
        ->and($events[2])->toEqual(new ToolInputDelta(0, 'toolu_a', '{"q":'))
        ->and($events[3])->toEqual(new ToolInputDelta(0, 'toolu_a', '"php"}'))
        ->and($events[4])->toEqual(new ToolInputEnd(0, 'toolu_a', 'lookup', ['q' => 'php']))
        ->and($events[5])->toEqual(new ToolInputStart(1, 'toolu_b', 'now'))
        ->and($events[6])->toEqual(new ToolInputEnd(1, 'toolu_b', 'now', []))
        ->and($events[7]->reason)->toBe(ProviderFinishReason::ToolUse)
        ->and($events[7]->rawReason)->toBe('tool_use');
});

test('streamEvents decodes empty or whitespace tool arguments as an empty object', function (string $json) {
    $events = anthropicStreamEvents(
        anthropicEvStart(),
        anthropicEvTool(0, 'toolu_1', 'now'),
        anthropicEvJson(0, $json),
        anthropicEvBlockStop(0),
        anthropicEvStop('tool_use'),
        ANTHROPIC_EV_MESSAGE_STOP,
    );
    $end = array_values(array_filter($events, fn($e) => $e instanceof ToolInputEnd));
    $finish = end($events);

    expect($end)->toHaveCount(1)
        ->and($end[0]->arguments)->toBe([])
        ->and($finish->reason)->toBe(ProviderFinishReason::ToolUse)
        ->and(json_encode($finish->contentBlocks))->toBe('[{"type":"tool_use","id":"toolu_1","name":"now","input":{}}]');
})->with(fn() => [
    'empty' => [''],
    'whitespace' => ['  '],
]);

test('streamEvents gives invalid tool arguments no ToolInputEnd and finishes with Error', function (string $json) {
    $events = anthropicStreamEvents(
        anthropicEvStart(),
        anthropicEvTool(0, 'toolu_1', 'lookup'),
        anthropicEvJson(0, $json),
        anthropicEvBlockStop(0),
        anthropicEvStop('tool_use'),
        ANTHROPIC_EV_MESSAGE_STOP,
    );
    $finish = end($events);

    expect(anthropicEventClasses($events))->toBe([ToolInputStart::class, ToolInputDelta::class, Finish::class])
        ->and($finish->reason)->toBe(ProviderFinishReason::Error)
        ->and($finish->rawReason)->toBe('tool_use')
        ->and($finish->contentBlocks)->toBe([]);
})->with(fn() => [
    'truncated object' => ['{"q": "ph'],
    'JSON array' => ['[1, 2]'],
    'JSON scalar' => ['"text"'],
]);

test('streamEvents combines message_start input and cache usage with message_delta output', function () {
    $events = anthropicStreamEvents(
        anthropicEvStart(['input_tokens' => 5, 'cache_read_input_tokens' => 40, 'cache_creation_input_tokens' => 10, 'output_tokens' => 1]),
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'x']],
        anthropicEvStop(null, 4),
        anthropicEvStop('end_turn', 7),
        ANTHROPIC_EV_MESSAGE_STOP,
    );

    expect(end($events)->usage)->toEqual(new Usage(55, 7, 62, 40, 10, null));
});

test('streamEvents ends with Finish Error when the stream stops before message_stop', function () {
    $events = anthropicStreamEvents(
        anthropicEvStart(),
        anthropicEvTool(0, 'toolu_1', 'lookup'),
        anthropicEvJson(0, '{"q":"a"}'),
        anthropicEvStop('tool_use'),
    );
    $finish = end($events);

    expect(array_filter($events, fn($e) => $e instanceof Finish))->toHaveCount(1)
        ->and($finish)->toBeInstanceOf(Finish::class)
        ->and($finish->reason)->toBe(ProviderFinishReason::Error)
        ->and($finish->rawReason)->toBe('')
        ->and($finish->contentBlocks)->toBe([])
        ->and(array_filter($events, fn($e) => $e instanceof ToolInputEnd))->toBe([]);
});

test('streamEvents yields a Tick during a silent gap', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, anthropicEventsBody(anthropicEvStart())), ScriptedStreamClient::TIMEOUT],
        [ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, anthropicEventsBody(anthropicEvStop('end_turn'), ANTHROPIC_EV_MESSAGE_STOP)), new LastChunk()],
    ]);
    $provider = (new AnthropicProvider(apiKey: 'test-key', httpClient: $client))
        ->withRequestLimits(new RequestLimits(idleTimeout: 5.0, tickInterval: 0.05));

    $events = array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));

    expect(anthropicEventClasses($events))->toBe([Tick::class, Tick::class, Finish::class])
        ->and($client->timeouts[0])->toBe(0.05)
        ->and($events[2]->reason)->toBe(ProviderFinishReason::Stop);
});

test('streamEvents cancels the HTTP response when the consumer stops iterating', function () {
    // MockHttpClient answers with a copy of the MockResponse, so keep the one request() returns.
    $client = new class (new MockHttpClient([new MockResponse(anthropicEventsBody(
        anthropicEvStart(),
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'a']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'b']],
        anthropicEvStop('end_turn'),
        ANTHROPIC_EV_MESSAGE_STOP,
    ), ['http_code' => 200])])) implements HttpClientInterface {
        use DecoratorTrait;

        public ?ResponseInterface $response = null;

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->response = $this->client->request($method, $url, $options);
        }
    };
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $client);

    $events = $provider->streamEvents([new UserMessage('hi')]);
    foreach ($events as $event) {
        break;
    }

    expect($client->response?->getInfo('canceled'))->not->toBeTrue();

    unset($events, $event);

    expect($client->response?->getInfo('canceled'))->toBeTrue();
});

test('chat normalises usage the same way as streaming', function () {
    $body = json_encode([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-test',
        'content' => [['type' => 'text', 'text' => 'Hi']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 5, 'cache_read_input_tokens' => 40, 'cache_creation_input_tokens' => 10, 'output_tokens' => 7],
    ]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: new MockHttpClient([new MockResponse($body, ['http_code' => 200])]));

    expect($provider->chat([new UserMessage('hi')])->usage)->toEqual(new Usage(55, 7, 62, 40, 10, null));
});

test('chat reports null cache fields when Anthropic omits them', function () {
    $body = json_encode([
        'model' => 'claude-test',
        'content' => [['type' => 'text', 'text' => 'Hi']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 5, 'output_tokens' => 7],
    ]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: new MockHttpClient([new MockResponse($body, ['http_code' => 200])]));

    expect($provider->chat([new UserMessage('hi')])->usage)->toEqual(new Usage(5, 7, 12));
});

test('streamEvents leaves the API error body readable on an HTTP error', function () {
    $client = new MockHttpClient([new MockResponse('{"type":"error","error":{"type":"invalid_request_error","message":"bad"}}', ['http_code' => 400])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $client);

    $thrown = null;
    try {
        iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false);
    } catch (Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown?->getResponse()->getContent(false))->toContain('"message":"bad"');
});

test('streamEvents keeps MaxTokens when a closed tool block has truncated arguments', function () {
    $events = anthropicStreamEvents(
        anthropicEvStart(),
        anthropicEvTool(0, 'toolu_1', 'lookup'),
        anthropicEvJson(0, '{"q": "ph'),
        anthropicEvBlockStop(0),
        anthropicEvStop('max_tokens'),
        ANTHROPIC_EV_MESSAGE_STOP,
    );
    $finish = end($events);

    expect(anthropicEventClasses($events))->toBe([ToolInputStart::class, ToolInputDelta::class, Finish::class])
        ->and($finish->reason)->toBe(ProviderFinishReason::MaxTokens)
        ->and($finish->rawReason)->toBe('max_tokens');
});
