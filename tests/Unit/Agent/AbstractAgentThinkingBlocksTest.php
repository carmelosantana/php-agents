<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Scripted provider: each stream() call yields the next list of chunks.
 */
final class ThinkingBlocksScriptedProvider implements ProviderInterface
{
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
        ->and(end($final)->contentBlocks())->toBe([['type' => 'text', 'text' => 'All done.']]);
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
