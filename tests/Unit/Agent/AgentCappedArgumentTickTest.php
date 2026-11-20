<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Tests\Support\Http\ScriptedStreamClient;

// A call over maxToolArgumentBytes is suppressed while the rest of it streams. The stream keeps
// yielding a Tick at least every tickInterval meanwhile, so a cancel or maxRunSeconds stops the
// provider promptly instead of at the end of the response.

const CAPPED_TICK_FRAGMENTS = 100;

/** @param list<array<string, mixed>> $events */
function cappedTickSse(array $events, bool $named = false): string
{
    return implode('', array_map(fn(array $e) => ($named ? 'event: ' . $e['type'] . "\n" : '') . 'data: ' . json_encode($e) . "\n\n", $events));
}

/**
 * The SSE text of one provider's tool call: its opening (with a 5-byte first fragment, under the
 * 8-byte cap), one more 10-byte fragment, and its close.
 *
 * @return array{string, string, string}
 */
function cappedTickBodies(string $class): array
{
    return match ($class) {
        OpenAICompatibleProvider::class => [
            cappedTickSse([['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c1', 'function' => ['name' => 'echo', 'arguments' => '{"t":']]]], 'finish_reason' => null]]]]),
            cappedTickSse([['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'aaaaaaaaaa']]]], 'finish_reason' => null]]]]),
            cappedTickSse([['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]]) . "data: [DONE]\n\n",
        ],
        AnthropicProvider::class => [
            cappedTickSse([
                ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'c1', 'name' => 'echo', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"t":']],
            ], true),
            cappedTickSse([['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'aaaaaaaaaa']]], true),
            cappedTickSse([
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
                ['type' => 'message_stop'],
            ], true),
        ],
        OpenAIResponsesProvider::class => [
            cappedTickSse([
                ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'echo', 'arguments' => '']],
                ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"t":'],
            ]),
            cappedTickSse([['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => 'aaaaaaaaaa']]),
            cappedTickSse([['type' => 'response.completed', 'response' => ['model' => 'gpt-test', 'status' => 'completed']]]),
        ],
    };
}

/**
 * An agent on $class whose one response streams a capped call as CAPPED_TICK_FRAGMENTS
 * fragments 10 ms apart; $pulled counts the fragments the provider read.
 *
 * @param class-string<ProviderInterface> $class
 */
function cappedTickAgent(string $class, int &$pulled, ?CancellationTokenInterface $token = null, ?RunLimits $runLimits = null): Agent
{
    [$open, $fragment, $close] = cappedTickBodies($class);
    $steps = [new FirstChunk(), new DataChunk(0, $open)];
    for ($i = 0; $i < CAPPED_TICK_FRAGMENTS; $i++) {
        $steps[] = 0.01;
        $steps[] = function () use (&$pulled): void {
            $pulled++;
        };
        $steps[] = new DataChunk(0, $fragment);
    }
    $steps[] = new DataChunk(0, $close);
    $steps[] = new LastChunk();
    $client = new ScriptedStreamClient([$steps]);
    $provider = (new $class(model: 'test-model', apiKey: 'key', httpClient: $client))
        ->withRequestLimits(new RequestLimits(idleTimeout: 5.0, tickInterval: 0.05, maxToolArgumentBytes: 8));

    return new Agent($provider, tools: [
        new Tool('echo', 'Echo.', [new StringParameter('t', 'Text')], fn(array $input): ToolResult => ToolResult::success('ok')),
    ], runLimits: $runLimits, cancellationToken: $token);
}

$cappedProviders = [
    'OpenAI-compatible' => OpenAICompatibleProvider::class,
    'Anthropic' => AnthropicProvider::class,
    'Responses' => OpenAIResponsesProvider::class,
];

test('a cancel after a call passes the argument cap stops the provider before the end of the stream', function (string $class) {
    $pulled = 0;
    $token = new class implements CancellationTokenInterface {
        public bool $cancelled = false;

        public function isCancelled(): bool
        {
            return $this->cancelled;
        }
    };
    $agent = cappedTickAgent($class, $pulled, $token);
    $agent->attach(new class($token) implements SplObserver {
        public function __construct(private object $token) {}

        public function update(SplSubject $subject): void
        {
            /** @var Agent $subject */
            if ($subject->lastEvent() === 'agent.tool_input_delta') {
                $this->token->cancelled = true;
            }
        }
    });

    $output = $agent->run(new UserMessage('Go.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($pulled)->toBeLessThan(CAPPED_TICK_FRAGMENTS / 2);
})->with($cappedProviders);

test('maxRunSeconds stops the provider while a call over the argument cap streams', function (string $class) {
    $pulled = 0;
    $agent = cappedTickAgent($class, $pulled, runLimits: new RunLimits(maxRunSeconds: 0.2));

    $output = $agent->run(new UserMessage('Go.'));

    expect($output->content)->toBe('Run time limit reached.')
        ->and($pulled)->toBeLessThan(CAPPED_TICK_FRAGMENTS / 2);
})->with($cappedProviders);
