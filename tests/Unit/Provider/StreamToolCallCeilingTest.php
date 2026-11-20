<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamProjector;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// A provider keeps per-call state while it streams. Past RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE
// calls in one response it stops reading and finishes Error (raw reason `tool_call_overflow`), so a
// hostile endpoint cannot grow that state within the per-request byte cap.

const CEILING_STARTS = 200_000;

/**
 * An SSE body opening CEILING_STARTS distinct tool calls, then finishing on tool use.
 */
function ceilingBody(string $class): string
{
    $body = '';
    for ($i = 0; $i < CEILING_STARTS; $i++) {
        $event = match ($class) {
            OpenAICompatibleProvider::class => ['choices' => [['delta' => ['tool_calls' => [['index' => $i, 'id' => "c{$i}", 'function' => ['name' => 'echo', 'arguments' => '']]]], 'finish_reason' => null]]],
            OpenAIResponsesProvider::class => ['type' => 'response.output_item.added', 'output_index' => $i, 'item' => ['type' => 'function_call', 'call_id' => "c{$i}", 'name' => 'echo', 'arguments' => '']],
            AnthropicProvider::class => ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => "c{$i}", 'name' => 'echo', 'input' => []]],
        };
        $body .= 'data: ' . json_encode($event) . "\n\n";
    }

    return $body . match ($class) {
        OpenAICompatibleProvider::class => 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\ndata: [DONE]\n\n",
        OpenAIResponsesProvider::class => 'data: ' . json_encode(['type' => 'response.completed', 'response' => ['model' => 'm', 'status' => 'completed']]) . "\n\n",
        AnthropicProvider::class => 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 1]]) . "\n\ndata: " . json_encode(['type' => 'message_stop']) . "\n\n",
    };
}

/**
 * @param class-string<ProviderInterface> $class
 */
function ceilingProvider(string $class): ProviderInterface
{
    $client = new MockHttpClient([new MockResponse(ceilingBody($class), ['http_code' => 200])]);

    return new $class(model: 'test-model', apiKey: 'key', httpClient: $client);
}

$ceilingProviders = [
    'OpenAI-compatible' => OpenAICompatibleProvider::class,
    'Anthropic' => AnthropicProvider::class,
    'Responses' => OpenAIResponsesProvider::class,
];

test('the agent and provider tool-call ceilings are one constant', function () {
    expect(AbstractAgent::MAX_TOOL_CALLS_PER_RESPONSE)->toBe(RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE);
});

test('a provider stops at the tool-call ceiling and finishes Error, keeping its state bounded', function (string $class) {
    $provider = ceilingProvider($class);
    $starts = 0;
    $events = 0;
    $finish = null;
    $memoryAtStart = null;
    $memoryGrowth = 0;

    foreach ($provider->streamEvents([new UserMessage('hi')]) as $event) {
        $events++;
        if ($event instanceof ToolInputStart && ++$starts === 100) {
            gc_collect_cycles();
            $memoryAtStart = memory_get_usage();
        }
        if ($event instanceof Finish) {
            gc_collect_cycles();
            $memoryGrowth = memory_get_usage() - (int) $memoryAtStart;
            $finish = $event;
        }
    }

    expect($starts)->toBe(RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE)
        ->and($finish?->reason)->toBe(ProviderFinishReason::Error)
        ->and($finish?->rawReason)->toBe('tool_call_overflow')
        ->and($events)->toBeLessThan(RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE + 10)
        ->and($memoryGrowth)->toBeLessThan(2_000_000);
})->with($ceilingProviders);

test('an agent run over a response past the tool-call ceiling ends cleanly as Error without running a tool', function (string $class) {
    $ran = 0;
    $agent = new Agent(ceilingProvider($class), tools: [
        new \CarmeloSantana\PHPAgents\Tool\Tool('echo', 'Echo.', [], function () use (&$ran) {
            $ran++;

            return \CarmeloSantana\PHPAgents\Tool\ToolResult::success('ok');
        }),
    ]);

    $output = $agent->run(new UserMessage('Go.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($ran)->toBe(0);
})->with($ceilingProviders);

test('StreamProjector keeps at most the ceiling of tool calls and then ends the projection with Error', function () {
    $events = (function (): \Generator {
        for ($i = 0; $i < CEILING_STARTS; $i++) {
            yield new ToolInputEnd($i, "c{$i}", 'echo', []);
        }
        yield new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'm');
    })();

    $responses = iterator_to_array(StreamProjector::toResponses($events), false);
    $last = end($responses);

    expect($responses)->toHaveCount(1)
        ->and($last)->toBeInstanceOf(Response::class)
        ->and($last->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($last->toolCalls)->toBe([]);
});
