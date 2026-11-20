<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Scripted provider: each stream() call yields the next list of chunks.
 */
final class ThinkingBlocksScriptedProvider implements ProviderInterface
{
    use StreamsEventsFromResponses;

    /** @var list<list<MessageInterface>> */
    public array $streamCalls = [];

    /**
     * @param list<list<Response>> $streams
     */
    public function __construct(private array $streams) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('chat() is not used by AbstractAgent::run().');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->streamCalls[] = $messages;

        $chunks = array_shift($this->streams) ?? throw new RuntimeException('No scripted stream left.');

        yield from $chunks;
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        throw new LogicException('structured() is not used by these tests.');
    }

    public function models(): array
    {
        return [new ModelDefinition(id: 'test-model', name: 'Test Model', provider: 'test', capabilities: [ModelCapability::Text, ModelCapability::Tools])];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return 'test-model';
    }

    public function withModel(string $model): static
    {
        return $this;
    }
}

final class ThinkingBlocksTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a thinking-blocks test agent.';
    }

    public function tools(): array
    {
        return [new Tool('ping', 'Return a long pong.', [], fn(array $input): ToolResult => ToolResult::success(str_repeat('pong ', 2000)))];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function thinkingBlocksFor(string $signature): array
{
    return [
        ['type' => 'thinking', 'thinking' => '', 'signature' => $signature],
        ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'ping', 'input' => new stdClass()],
    ];
}

/**
 * The first stream calls ping with thinking blocks and ends with a block-less
 * Stop chunk; the second answers in text with its own blocks.
 *
 * @return list<list<Response>>
 */
function thinkingBlocksToolRoundStreams(): array
{
    return [
        [
            new Response(content: '', finishReason: ProviderFinishReason::Stop, reasoning: 'thinking'),
            new Response(content: '', finishReason: ProviderFinishReason::ToolUse, toolCalls: [new ToolCall('call-1', 'ping', [])], contentBlocks: thinkingBlocksFor('sig-round-1')),
            new Response(content: '', finishReason: ProviderFinishReason::Stop),
        ],
        [
            new Response(content: 'All done.', finishReason: ProviderFinishReason::Stop),
            new Response(content: '', finishReason: ProviderFinishReason::Stop, contentBlocks: [['type' => 'text', 'text' => 'All done.']]),
        ],
    ];
}

/**
 * @param list<MessageInterface> $messages
 * @return list<AssistantMessage>
 */
function thinkingBlocksAssistantMessages(array $messages): array
{
    return array_values(array_filter($messages, fn(MessageInterface $m): bool => $m instanceof AssistantMessage));
}

test('in a tool round the second stream() call receives the content blocks', function () {
    $provider = new ThinkingBlocksScriptedProvider(thinkingBlocksToolRoundStreams());
    $output = (new ThinkingBlocksTestAgent(provider: $provider))->run(new UserMessage('Hi'));

    $replayed = thinkingBlocksAssistantMessages($provider->streamCalls[1]);
    $final = thinkingBlocksAssistantMessages($output->conversation->messages());

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($replayed)->toHaveCount(1)
        ->and($replayed[0]->contentBlocks())->toEqual(thinkingBlocksFor('sig-round-1'))
        ->and($replayed[0]->toolCalls()[0]->name)->toBe('ping')
        ->and(end($final)->content())->toBe('All done.')
        ->and(end($final)->contentBlocks())->toBe([]);
});

test('the request after pruning has no content blocks on any AssistantMessage', function () {
    $provider = new ThinkingBlocksScriptedProvider(thinkingBlocksToolRoundStreams());
    $agent = new ThinkingBlocksTestAgent(provider: $provider, contextWindow: new ContextWindow(maxTok: 1_000, reservedTok: 0));

    $output = $agent->run(new UserMessage('Hi'));

    $afterPruning = $provider->streamCalls[1];

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and(strlen((string) end($afterPruning)->content()))->toBeLessThan(10_000)
        ->and(thinkingBlocksAssistantMessages($afterPruning))->toHaveCount(1)
        ->and(array_map(fn(AssistantMessage $m): array => $m->contentBlocks(), thinkingBlocksAssistantMessages($afterPruning)))->toBe([[]]);
});

test('a context window that does not prune keeps the content blocks', function () {
    $provider = new ThinkingBlocksScriptedProvider(thinkingBlocksToolRoundStreams());
    $agent = new ThinkingBlocksTestAgent(provider: $provider, contextWindow: new ContextWindow(maxTok: 1_000_000, reservedTok: 0));

    $agent->run(new UserMessage('Hi'));

    expect(thinkingBlocksAssistantMessages($provider->streamCalls[1])[0]->contentBlocks())->toEqual(thinkingBlocksFor('sig-round-1'));
});

test('history passed into run() arrives without content blocks', function () {
    $history = new Conversation();
    $history->add(new UserMessage('Earlier question'));
    $history->add(new AssistantMessage('Earlier answer', [], [['type' => 'thinking', 'thinking' => '', 'signature' => 'old-sig'], ['type' => 'text', 'text' => 'Earlier answer']]));

    $provider = new ThinkingBlocksScriptedProvider([[new Response(content: 'Hello.', finishReason: ProviderFinishReason::Stop)]]);
    (new ThinkingBlocksTestAgent(provider: $provider))->run(new UserMessage('Hi'), $history);

    $sent = thinkingBlocksAssistantMessages($provider->streamCalls[0]);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->content())->toBe('Earlier answer')
        ->and($sent[0]->contentBlocks())->toBe([]);
});

/**
 * An Anthropic SSE body built from event payloads.
 *
 * @param array<string, mixed> ...$events
 */
function thinkingBlocksSse(array ...$events): MockResponse
{
    $body = '';
    foreach ($events as $event) {
        $body .= 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event) . "\n\n";
    }

    return new MockResponse($body, ['http_code' => 200]);
}

/** A streamed turn that ends on end_turn with only a signed thinking block and no text. */
function thinkingOnlyStopSse(): MockResponse
{
    return thinkingBlocksSse(
        ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Hmm.']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-empty']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]],
        ['type' => 'message_stop'],
    );
}

function textAnswerSse(string $text): MockResponse
{
    return thinkingBlocksSse(
        ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]],
        ['type' => 'message_stop'],
    );
}

test('a thinking-only Stop turn is not sent back as an assistant turn', function (EmptyResponseHandling $handling) {
    $bodies = [];
    $responses = [thinkingOnlyStopSse(), textAnswerSse('Answer.')];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies, &$responses): MockResponse {
        $bodies[] = $options['body'];

        return array_shift($responses);
    });
    $agent = new ThinkingBlocksTestAgent(
        provider: new AnthropicProvider(apiKey: 'test-key', httpClient: $client),
        emptyResponseHandling: $handling,
    );

    $output = $agent->run(new UserMessage('Hi'));
    $messages = json_decode($bodies[1], true)['messages'];

    expect($output->content)->toBe('Answer.')
        ->and($bodies)->toHaveCount(2)
        ->and(end($messages)['role'])->toBe('user')
        ->and(array_column($messages, 'role'))->not->toContain('assistant')
        ->and($bodies[1])->not->toContain('"type":"thinking"')
        ->and($bodies[1])->not->toContain('sig-empty');
})->with([
    'ignore' => [EmptyResponseHandling::Ignore],
    'nudge' => [EmptyResponseHandling::Nudge],
]);

test('fallbackToReasoning adds its answer without content blocks', function () {
    $provider = new ThinkingBlocksScriptedProvider([[
        new Response(content: '', finishReason: ProviderFinishReason::Stop, reasoning: 'The answer is 42.'),
        new Response(content: '', finishReason: ProviderFinishReason::Stop, contentBlocks: [['type' => 'thinking', 'thinking' => 'The answer is 42.', 'signature' => 'sig-fallback']]),
    ]]);
    $agent = new ThinkingBlocksTestAgent(provider: $provider, emptyResponseHandling: EmptyResponseHandling::Fallback);

    $output = $agent->run(new UserMessage('Hi'));
    $final = thinkingBlocksAssistantMessages($output->conversation->messages());

    expect($output->content)->toBe('The answer is 42.')
        ->and($final)->toHaveCount(1)
        ->and($final[0]->contentBlocks())->toBe([]);
});

test('a tool_use block for a call past maxToolCallsPerTurn is not sent back to Anthropic', function () {
    $bodies = [];
    $toolUse = fn(int $index, string $id): array => [
        ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => 'ping', 'input' => []]],
        ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ['type' => 'content_block_stop', 'index' => $index],
    ];
    $responses = [
        thinkingBlocksSse(...[
            ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Two pings.']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-capped']],
            ['type' => 'content_block_stop', 'index' => 0],
            ...$toolUse(1, 'toolu_1'),
            ...$toolUse(2, 'toolu_2'),
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
            ['type' => 'message_stop'],
        ]),
        textAnswerSse('Answer.'),
    ];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies, &$responses): MockResponse {
        $bodies[] = $options['body'];

        return array_shift($responses);
    });
    $agent = new ThinkingBlocksTestAgent(
        provider: new AnthropicProvider(apiKey: 'test-key', httpClient: $client),
        runLimits: new \CarmeloSantana\PHPAgents\Agent\RunLimits(maxToolCallsPerTurn: 1),
    );

    $output = $agent->run(new UserMessage('Hi'));
    $messages = json_decode($bodies[1], true)['messages'];
    $assistant = array_values(array_filter($messages, fn(array $m): bool => $m['role'] === 'assistant'));

    expect($output->content)->toBe('Answer.')
        ->and($assistant)->toHaveCount(1)
        ->and(array_column($assistant[0]['content'], 'type'))->toBe(['thinking', 'tool_use'])
        ->and($assistant[0]['content'][0]['signature'])->toBe('sig-capped')
        ->and($assistant[0]['content'][1]['id'])->toBe('toolu_1')
        ->and($bodies[1])->not->toContain('toolu_2');
});
