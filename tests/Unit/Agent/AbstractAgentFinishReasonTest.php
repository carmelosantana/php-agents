<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Scripted provider: each stream() call yields the next list of chunks.
 */
final class FinishReasonScriptedProvider implements ProviderInterface
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

final class FinishReasonSpyExecutor implements ToolExecutorInterface
{
    public int $calls = 0;

    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        $this->calls++;

        return $tool->execute($arguments);
    }
}

final class FinishReasonEventRecorder implements SplObserver
{
    /** @var list<array{string, mixed}> */
    public array $events = [];

    public function update(SplSubject $subject): void
    {
        /** @var AbstractAgent $subject */
        $this->events[] = [$subject->lastEvent(), $subject->lastEventData()];
    }

    /**
     * @return list<mixed>
     */
    public function dataFor(string $event): array
    {
        return array_values(array_map(
            fn(array $e): mixed => $e[1],
            array_filter($this->events, fn(array $e): bool => $e[0] === $event),
        ));
    }
}

final class FinishReasonTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a finish-reason test agent.';
    }

    public function tools(): array
    {
        return [new Tool('ping', 'Return pong.', [], fn(array $input): ToolResult => ToolResult::success('pong'))];
    }
}

function finishReasonChunk(ProviderFinishReason $reason, string $content = '', array $toolCalls = [], ?Usage $usage = null): Response
{
    return new Response(content: $content, finishReason: $reason, toolCalls: $toolCalls, model: 'test-model', usage: $usage);
}

/**
 * @param list<list<Response>> $streams
 * @return array{Output, FinishReasonScriptedProvider, FinishReasonSpyExecutor, FinishReasonEventRecorder}
 */
function runFinishReasonAgent(array $streams): array
{
    $provider = new FinishReasonScriptedProvider($streams);
    $executor = new FinishReasonSpyExecutor();
    $recorder = new FinishReasonEventRecorder();

    $agent = new FinishReasonTestAgent(provider: $provider, toolExecutor: $executor);
    $agent->attach($recorder);

    return [$agent->run(new UserMessage('Hi')), $provider, $executor, $recorder];
}

function finishReasonUserMessages(Output $output): array
{
    return array_values(array_filter(
        $output->conversation?->messages() ?? [],
        fn(MessageInterface $m): bool => $m->role() === Role::User,
    ));
}

function finishReasonPingCall(): ToolCall
{
    return new ToolCall('call-1', 'ping', []);
}

function finishReasonDoneCall(): ToolCall
{
    return new ToolCall('call-2', 'done', ['response' => 'All done.']);
}

dataset('failed stops', [
    'refusal after text' => [
        [finishReasonChunk(ProviderFinishReason::Stop, 'I cannot'), finishReasonChunk(ProviderFinishReason::Refusal, ' help with that.')],
        ProviderFinishReason::Refusal, AgentFinishReason::Refusal, 'I cannot help with that.', true,
    ],
    'refusal after tool calls' => [
        [finishReasonChunk(ProviderFinishReason::ToolUse, 'Let me', [finishReasonPingCall()]), finishReasonChunk(ProviderFinishReason::Refusal)],
        ProviderFinishReason::Refusal, AgentFinishReason::Refusal, 'Let me', true,
    ],
    'content filter after text' => [
        [finishReasonChunk(ProviderFinishReason::Stop, 'Partial'), finishReasonChunk(ProviderFinishReason::ContentFilter)],
        ProviderFinishReason::ContentFilter, AgentFinishReason::ContentFilter, 'Partial', true,
    ],
    'context window exceeded' => [
        [finishReasonChunk(ProviderFinishReason::Stop, 'Partial'), finishReasonChunk(ProviderFinishReason::ContextWindowExceeded)],
        ProviderFinishReason::ContextWindowExceeded, AgentFinishReason::ContextWindowExceeded, 'Partial', false,
    ],
    'text then error' => [
        [finishReasonChunk(ProviderFinishReason::Stop, 'Half an answer'), finishReasonChunk(ProviderFinishReason::Error)],
        ProviderFinishReason::Error, AgentFinishReason::Error, 'Provider error: the response ended without a valid finish reason.', false,
    ],
    'tool calls then error' => [
        [finishReasonChunk(ProviderFinishReason::ToolUse, '', [finishReasonPingCall(), finishReasonDoneCall()]), finishReasonChunk(ProviderFinishReason::Error)],
        ProviderFinishReason::Error, AgentFinishReason::Error, 'Provider error: the response ended without a valid finish reason.', false,
    ],
    'pause turn' => [
        [finishReasonChunk(ProviderFinishReason::Stop, 'Searching'), finishReasonChunk(ProviderFinishReason::PauseTurn)],
        ProviderFinishReason::PauseTurn, AgentFinishReason::Error, 'Provider paused the turn (pause_turn); server-tool continuation is not supported.', false,
    ],
    'gemini tool calls then content filter' => [
        [finishReasonChunk(ProviderFinishReason::ToolUse, '', [finishReasonPingCall()]), finishReasonChunk(ProviderFinishReason::ContentFilter)],
        ProviderFinishReason::ContentFilter, AgentFinishReason::ContentFilter, '', true,
    ],
    'gemini tool calls then trailing error' => [
        [finishReasonChunk(ProviderFinishReason::ToolUse, '', [finishReasonDoneCall()]), finishReasonChunk(ProviderFinishReason::Error)],
        ProviderFinishReason::Error, AgentFinishReason::Error, 'Provider error: the response ended without a valid finish reason.', false,
    ],
]);

test('a failed provider stop ends the run before done, tools or text', function (
    array $chunks,
    ProviderFinishReason $providerReason,
    AgentFinishReason $agentReason,
    string $content,
    bool $addsAssistantTurn,
) {
    [$output, $provider, $executor, $recorder] = runFinishReasonAgent([$chunks]);

    expect($output->finishReason)->toBe($agentReason)
        ->and($output->providerFinishReason)->toBe($providerReason)
        ->and($output->content)->toBe($content)
        ->and($executor->calls)->toBe(0)
        ->and($recorder->dataFor('agent.done'))->toBe([])
        ->and($recorder->dataFor('agent.tool_call'))->toBe([])
        ->and($provider->streamCalls)->toHaveCount(1)
        ->and(finishReasonUserMessages($output))->toHaveCount(1);

    $last = $output->conversation?->messages()[array_key_last($output->conversation->messages())];
    if ($addsAssistantTurn) {
        expect($last->role())->toBe(Role::Assistant)
            ->and($last->content())->toBe($content)
            ->and($last->toolCalls())->toBe([]);
    } else {
        expect($last->role())->toBe(Role::User);
    }

    $errors = $recorder->dataFor('agent.error');
    expect($errors)->toBe($agentReason === AgentFinishReason::Error ? [$content] : []);
})->with('failed stops');

test('an error chunk still counts its usage', function () {
    [$output] = runFinishReasonAgent([[
        finishReasonChunk(ProviderFinishReason::Stop, 'Half'),
        finishReasonChunk(ProviderFinishReason::Error, usage: new Usage(promptTokens: 10, completionTokens: 5, totalTokens: 15)),
    ]]);

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($output->usage?->totalTokens)->toBe(15);
});

test('max tokens returns the text as Stop and keeps the provider reason', function () {
    [$output, , $executor, $recorder] = runFinishReasonAgent([[
        finishReasonChunk(ProviderFinishReason::Stop, 'Cut off'),
        finishReasonChunk(ProviderFinishReason::MaxTokens),
    ]]);

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->providerFinishReason)->toBe(ProviderFinishReason::MaxTokens)
        ->and($output->content)->toBe('Cut off')
        ->and($executor->calls)->toBe(0)
        ->and($recorder->dataFor('agent.done'))->toBe([['response' => 'Cut off']]);
});

test('max tokens with tool calls returns the text as Stop and runs no tool', function () {
    [$output, $provider, $executor] = runFinishReasonAgent([[
        finishReasonChunk(ProviderFinishReason::Stop, 'Calling ping'),
        finishReasonChunk(ProviderFinishReason::MaxTokens, '', [finishReasonPingCall()]),
    ]]);

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->providerFinishReason)->toBe(ProviderFinishReason::MaxTokens)
        ->and($output->content)->toBe('Calling ping')
        ->and($executor->calls)->toBe(0)
        ->and($provider->streamCalls)->toHaveCount(1)
        ->and($output->conversation?->messages()[array_key_last($output->conversation->messages())]->toolCalls())->toBe([]);
});

test('an empty ToolUse goes to the empty-response nudge', function () {
    [$output, $provider, $executor] = runFinishReasonAgent([
        [finishReasonChunk(ProviderFinishReason::ToolUse)],
        [finishReasonChunk(ProviderFinishReason::Stop, 'Recovered.')],
    ]);

    $users = finishReasonUserMessages($output);

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('Recovered.')
        ->and($output->providerFinishReason)->toBe(ProviderFinishReason::Stop)
        ->and($executor->calls)->toBe(0)
        ->and($provider->streamCalls)->toHaveCount(2)
        ->and($users)->toHaveCount(2)
        ->and($users[1]->content())->toContain('no final answer text');
});

test('a ToolUse stop runs the tools and reports the provider reason on the final output', function () {
    [$output, $provider, $executor] = runFinishReasonAgent([
        [finishReasonChunk(ProviderFinishReason::ToolUse, '', [finishReasonPingCall()])],
        [finishReasonChunk(ProviderFinishReason::ToolUse, '', [finishReasonDoneCall()])],
    ]);

    expect($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($output->providerFinishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($executor->calls)->toBe(1)
        ->and($provider->streamCalls)->toHaveCount(2);
});

test('tool calls on Stop chunks with no terminal reason still run as ToolUse', function () {
    [$output, , $executor] = runFinishReasonAgent([
        [finishReasonChunk(ProviderFinishReason::Stop, '', [finishReasonPingCall()])],
        [finishReasonChunk(ProviderFinishReason::Stop, 'Pong received.')],
    ]);

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->providerFinishReason)->toBe(ProviderFinishReason::Stop)
        ->and($executor->calls)->toBe(1);
});
