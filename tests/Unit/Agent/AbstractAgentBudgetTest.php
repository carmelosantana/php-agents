<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

final class RecordingProvider implements ProviderInterface
{
    /** @var list<Response|\Closure> */
    private array $scripts;

    /** @var list<list<MessageInterface>> */
    public array $streamCalls = [];

    /**
     * @param list<Response|\Closure> $scripts
     */
    public function __construct(array $scripts)
    {
        $this->scripts = $scripts;
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('chat() is not used by AbstractAgent::run().');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->streamCalls[] = $messages;

        $script = array_shift($this->scripts);
        if ($script === null) {
            throw new RuntimeException('No scripted stream response available.');
        }

        $response = $script instanceof Closure
            ? $script($messages, $tools, $options)
            : $script;

        yield $response;
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        throw new LogicException('structured() is not used by these tests.');
    }

    public function models(): array
    {
        return [
            new ModelDefinition(
                id: 'test-model',
                name: 'Test Model',
                provider: 'test',
                capabilities: [ModelCapability::Text, ModelCapability::Tools],
            ),
        ];
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

final class BudgetWarningObserver implements SplObserver, PendingInputProviderInterface
{
    /** @var list<array{event: string, data: mixed}> */
    public array $events = [];

    /** @var list<MessageInterface> */
    private array $pendingInputs = [];

    public function update(SplSubject $subject): void
    {
        if (!$subject instanceof AbstractAgent) {
            return;
        }

        $event = $subject->lastEvent();
        $data = $subject->lastEventData();

        $this->events[] = ['event' => $event, 'data' => $data];

        if ($event === 'agent.budget_warning') {
            $this->pendingInputs[] = new UserMessage('[budget-wrap-up]');
        }
    }

    public function consumePendingInputs(): array
    {
        $messages = $this->pendingInputs;
        $this->pendingInputs = [];

        return $messages;
    }
}

final class NoOpTool implements ToolInterface
{
    public function name(): string
    {
        return 'noop';
    }

    public function description(): string
    {
        return 'Return a fixed result for test execution.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        return ToolResult::success('noop complete');
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'noop',
                'description' => 'Return a fixed result for test execution.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
        ];
    }
}

final class BudgetTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a budget test agent.';
    }

    public function tools(): array
    {
        return [new NoOpTool()];
    }
}

test('budget warning fires once and injects pending input on the next iteration', function () {
    $provider = new RecordingProvider([
        new Response(
            content: '',
            finishReason: ProviderFinishReason::ToolUse,
            toolCalls: [new ToolCall('call-1', 'noop', [])],
            model: 'test-model',
            usage: new Usage(promptTokens: 700, completionTokens: 150, totalTokens: 850),
        ),
        new Response(
            content: '',
            finishReason: ProviderFinishReason::ToolUse,
            toolCalls: [new ToolCall('call-2', 'done', ['response' => 'Wrapped up'])],
            model: 'test-model',
            usage: new Usage(promptTokens: 650, completionTokens: 100, totalTokens: 750),
        ),
    ]);

    $observer = new BudgetWarningObserver();
    $agent = new BudgetTestAgent(
        provider: $provider,
        pendingInputProvider: $observer,
        contextWindow: new ContextWindow(maxTok: 1_000, reservedTok: 0),
        budgetExitThreshold: 0.8,
        budgetExitWrapUpIterations: 2,
    );
    $agent->attach($observer);

    $output = $agent->run(new UserMessage('Start the task.'));

    $warningEvents = array_values(array_filter(
        $observer->events,
        static fn(array $event): bool => $event['event'] === 'agent.budget_warning',
    ));

    expect($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($output->content)->toBe('Wrapped up')
        ->and($warningEvents)->toHaveCount(1)
        ->and($warningEvents[0]['data'])->toMatchArray([
            'threshold' => 0.8,
            'wrapUpIterations' => 2,
        ])
        ->and($provider->streamCalls)->toHaveCount(2)
        ->and(end($provider->streamCalls[1])->content())->toBe('[budget-wrap-up]');
});

test('agent exits with budget exhausted after the wrap-up window expires', function () {
    $provider = new RecordingProvider([
        new Response(
            content: '',
            finishReason: ProviderFinishReason::ToolUse,
            toolCalls: [new ToolCall('call-1', 'noop', [])],
            model: 'test-model',
            usage: new Usage(promptTokens: 700, completionTokens: 150, totalTokens: 850),
        ),
        new Response(
            content: '',
            finishReason: ProviderFinishReason::ToolUse,
            toolCalls: [new ToolCall('call-2', 'noop', [])],
            model: 'test-model',
            usage: new Usage(promptTokens: 680, completionTokens: 120, totalTokens: 800),
        ),
    ]);

    $agent = new BudgetTestAgent(
        provider: $provider,
        contextWindow: new ContextWindow(maxTok: 1_000, reservedTok: 0),
        budgetExitThreshold: 0.8,
        budgetExitWrapUpIterations: 1,
    );

    $output = $agent->run(new UserMessage('Keep going.'));

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toContain('Context budget exhausted')
        ->and($output->iterations)->toBe(3)
        ->and($provider->streamCalls)->toHaveCount(2);
});

test('budget exit threshold is ignored when disabled', function () {
    $provider = new RecordingProvider([
        new Response(
            content: 'All done.',
            finishReason: ProviderFinishReason::Stop,
            model: 'test-model',
            usage: new Usage(promptTokens: 700, completionTokens: 150, totalTokens: 850),
        ),
    ]);

    $observer = new BudgetWarningObserver();
    $agent = new BudgetTestAgent(
        provider: $provider,
        pendingInputProvider: $observer,
        contextWindow: new ContextWindow(maxTok: 1_000, reservedTok: 0),
        budgetExitThreshold: 0.0,
    );
    $agent->attach($observer);

    $output = $agent->run(new UserMessage('Reply once.'));

    $warningEvents = array_filter(
        $observer->events,
        static fn(array $event): bool => $event['event'] === 'agent.budget_warning',
    );

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('All done.')
        ->and($warningEvents)->toBeEmpty();
});

test('budget exit threshold requires a context window', function () {
    $provider = new RecordingProvider([
        new Response(
            content: 'No context window configured.',
            finishReason: ProviderFinishReason::Stop,
            model: 'test-model',
            usage: new Usage(promptTokens: 700, completionTokens: 150, totalTokens: 850),
        ),
    ]);

    $observer = new BudgetWarningObserver();
    $agent = new BudgetTestAgent(
        provider: $provider,
        pendingInputProvider: $observer,
        budgetExitThreshold: 0.8,
    );
    $agent->attach($observer);

    $output = $agent->run(new UserMessage('Reply once.'));

    $warningEvents = array_filter(
        $observer->events,
        static fn(array $event): bool => $event['event'] === 'agent.budget_warning',
    );

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($warningEvents)->toBeEmpty();
});

test('provider exceptions surface as error finish reason', function () {
    $provider = new RecordingProvider([
        static fn(): never => throw new RuntimeException('boom'),
    ]);

    $agent = new BudgetTestAgent(provider: $provider);

    $output = $agent->run(new UserMessage('Trigger failure.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($output->content)->toContain('Provider error: boom');
});

test('max iteration exhaustion returns the max iterations finish reason', function () {
    $provider = new RecordingProvider([
        new Response(content: '', finishReason: ProviderFinishReason::Stop, model: 'test-model'),
    ]);

    $agent = new BudgetTestAgent(provider: $provider, maxIter: 1);

    $output = $agent->run(new UserMessage('Do nothing.'));

    expect($output->finishReason)->toBe(AgentFinishReason::MaxIterations)
        ->and($output->content)->toContain('maximum iterations');
});