<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Once one call's streamed arguments pass RequestLimits::$maxToolArgumentBytes, the provider
// stops keeping and yielding them, keeps reading the stream, and drops the call as one whose
// arguments are not a JSON object. A whole-call provider checks the arguments before it yields
// their delta.

const ARG_CAP = 16;

/**
 * Streamed events of $class for an SSE $body, under a 16-byte argument cap.
 *
 * @param class-string<ProviderInterface> $class
 * @return list<object>
 */
function argCapEvents(string $class, string $body): array
{
    $client = new MockHttpClient([new MockResponse($body, ['http_code' => 200])]);
    $provider = (new $class(model: 'test-model', apiKey: 'key', httpClient: $client))
        ->withRequestLimits(new RequestLimits(maxToolArgumentBytes: ARG_CAP));

    return array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false));
}

/** @param list<array<string, mixed>> $events */
function argCapSse(array $events, bool $named = false): string
{
    return implode('', array_map(fn(array $e) => ($named ? 'event: ' . $e['type'] . "\n" : '') . 'data: ' . json_encode($e) . "\n\n", $events));
}

/**
 * The argument fragments: 9 bytes, then 12 (21 in all, past the cap), then 2.
 */
const ARG_CAP_FRAGMENTS = ['{"text":"', 'aaaaaaaaaaaa', '"}'];

/**
 * @param list<object> $events
 * @return array{list<string>, list<string>, ?ProviderFinishReason}
 */
function argCapSummary(array $events): array
{
    $deltas = [];
    $ends = [];
    $finish = null;
    foreach ($events as $event) {
        if ($event instanceof ToolInputDelta) {
            $deltas[] = $event->id . ':' . $event->delta;
        } elseif ($event instanceof ToolInputEnd) {
            $ends[] = $event->id;
        } elseif ($event instanceof Finish) {
            $finish = $event->reason;
        }
    }

    return [$deltas, $ends, $finish];
}

test('OpenAI-compatible stops yielding a call\'s argument deltas past the byte cap and drops it', function () {
    $chunk = fn(array $call, ?string $finish = null) => ['choices' => [['delta' => ['tool_calls' => [$call]], 'finish_reason' => $finish]]];
    $body = argCapSse([
        $chunk(['index' => 0, 'id' => 'c1', 'function' => ['name' => 'echo', 'arguments' => ARG_CAP_FRAGMENTS[0]]]),
        $chunk(['index' => 0, 'function' => ['arguments' => ARG_CAP_FRAGMENTS[1]]]),
        $chunk(['index' => 0, 'function' => ['arguments' => ARG_CAP_FRAGMENTS[2]]]),
        $chunk(['index' => 1, 'id' => 'c2', 'function' => ['name' => 'now', 'arguments' => '{}']], 'tool_calls'),
    ]) . "data: [DONE]\n\n";

    expect(argCapSummary(argCapEvents(OpenAICompatibleProvider::class, $body)))
        ->toBe([['c1:{"text":"', 'c2:{}'], ['c2'], ProviderFinishReason::Error]);
});

test('Anthropic stops yielding a tool_use block\'s argument deltas past the byte cap and drops it', function () {
    $json = fn(int $index, string $fragment) => ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $fragment]];
    $body = argCapSse([
        ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'c1', 'name' => 'echo', 'input' => []]],
        ...array_map(fn(string $f) => $json(0, $f), ARG_CAP_FRAGMENTS),
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'c2', 'name' => 'now', 'input' => []]],
        $json(1, '{}'),
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
        ['type' => 'message_stop'],
    ], true);

    expect(argCapSummary(argCapEvents(AnthropicProvider::class, $body)))
        ->toBe([['c1:{"text":"', 'c2:{}'], ['c2'], ProviderFinishReason::Error]);
});

test('Responses stops yielding a call\'s argument deltas past the byte cap and drops it, done item included', function () {
    $item = fn(string $type, int $at, string $id, string $name, string $arguments) => ['type' => $type, 'output_index' => $at, 'item' => ['type' => 'function_call', 'call_id' => $id, 'name' => $name, 'arguments' => $arguments]];
    $body = argCapSse([
        $item('response.output_item.added', 0, 'c1', 'echo', ''),
        ...array_map(fn(string $f) => ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => $f], ARG_CAP_FRAGMENTS),
        $item('response.output_item.done', 0, 'c1', 'echo', implode('', ARG_CAP_FRAGMENTS)),
        $item('response.output_item.added', 1, 'c2', 'now', ''),
        $item('response.output_item.done', 1, 'c2', 'now', '{}'),
        ['type' => 'response.completed', 'response' => ['model' => 'gpt-test', 'status' => 'completed']],
    ]);

    expect(argCapSummary(argCapEvents(OpenAIResponsesProvider::class, $body)))
        ->toBe([['c1:{"text":"', 'c2:{}'], ['c2'], ProviderFinishReason::Error]);
});

test('Responses does not yield a done item\'s arguments over the byte cap when none streamed', function () {
    $item = fn(string $type, string $arguments) => ['type' => $type, 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'echo', 'arguments' => $arguments]];
    $body = argCapSse([
        $item('response.output_item.added', ''),
        $item('response.output_item.done', implode('', ARG_CAP_FRAGMENTS)),
        ['type' => 'response.completed', 'response' => ['model' => 'gpt-test', 'status' => 'completed']],
    ]);

    expect(argCapSummary(argCapEvents(OpenAIResponsesProvider::class, $body)))
        ->toBe([[], [], ProviderFinishReason::Error]);
});

test('Gemini checks a call\'s arguments against the caps before it yields their delta', function () {
    $body = argCapSse([[
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'echo', 'args' => ['text' => str_repeat('a', 20)]]],
                ['functionCall' => ['name' => 'now', 'args' => new stdClass()]],
            ]],
            'finishReason' => 'STOP',
        ]],
    ]]);

    $events = argCapEvents(GeminiProvider::class, $body);

    expect(argCapSummary($events))->toBe([['g00000001:{}'], ['g00000001'], ProviderFinishReason::Error])
        ->and(array_map(fn(ToolInputStart $s) => $s->name, array_values(array_filter($events, fn($e) => $e instanceof ToolInputStart))))->toBe(['echo', 'now']);
});
