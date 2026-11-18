<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

// A provider (or a proxy in front of it) controls the usage it reports, how much it streams and how
// long it takes. These pin the provider-independent cumulative limits in RunLimits: wall time,
// response bytes, local token estimates and the size of the new input.

/**
 * Each stream() call runs the next script, a closure returning the chunks to yield (any iterable,
 * so a generator can sleep or count). Records how many calls and chunks the agent consumed.
 */
final class RunLimitsScriptedProvider implements ProviderInterface
{
    public int $calls = 0;

    public int $chunksConsumed = 0;

    /** @param list<\Closure(): iterable<Response>> $scripts */
    public function __construct(private array $scripts, private ?\Closure $repeat = null) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('chat() is not used by AbstractAgent::run().');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls++;
        $script = array_shift($this->scripts) ?? $this->repeat ?? throw new RuntimeException('No scripted stream left.');

        foreach ($script() as $chunk) {
            $this->chunksConsumed++;
            yield $chunk;
        }
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

final class RunLimitsEventRecorder implements SplObserver
{
    /** @var list<array{string, mixed}> */
    public array $events = [];

    public function update(SplSubject $subject): void
    {
        /** @var AbstractAgent $subject */
        $this->events[] = [$subject->lastEvent(), $subject->lastEventData()];
    }

    /** @return list<mixed> */
    public function dataFor(string $event): array
    {
        return array_values(array_map(
            fn(array $e): mixed => $e[1],
            array_filter($this->events, fn(array $e): bool => $e[0] === $event),
        ));
    }
}

final class RunLimitsTestAgent extends AbstractAgent
{
    /** @var list<string> */
    public array $ran = [];

    /** @var list<ToolInterface> */
    public array $extraTools = [];

    public function instructions(): string
    {
        return 'You are a run limits test agent.';
    }

    public function tools(): array
    {
        $tools = $this->extraTools;
        foreach (['first', 'second'] as $name) {
            $tools[] = new Tool($name, "The {$name} tool.", [], function (array $input) use ($name): ToolResult {
                $this->ran[] = $name;

                return ToolResult::success("{$name} ran");
            });
        }

        return $tools;
    }
}

/** A policy that takes its time, so the wall clock moves between tool pre-flight and execution. */
final class RunLimitsSlowPolicy implements ToolExecutionPolicyInterface
{
    public function __construct(private int $microseconds) {}

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        usleep($this->microseconds);

        return true;
    }
}

final class RunLimitsBatchExecutor implements BatchToolExecutorInterface
{
    public int $batches = 0;

    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        return $tool->execute($arguments);
    }

    public function executeBatch(array $batch): array
    {
        $this->batches++;

        return array_map(fn(array $entry): ToolResult => $entry['tool']->execute($entry['arguments']), $batch);
    }
}

/** A context window that always claims to be empty, whatever it is told. */
final class RunLimitsUnderReportingWindow implements ContextWindowInterface
{
    public function maxTokens(): int
    {
        return 1_000;
    }

    public function reservedTokens(): int
    {
        return 0;
    }

    public function usedTokens(): int
    {
        return 0;
    }

    public function availableTokens(): int
    {
        return 1_000;
    }

    public function usagePercent(): float
    {
        return 0.0;
    }

    public function estimate(int $tokens): void {}

    public function report(Usage $usage): void {}

    public function exceeds(float $thresholdPercent): bool
    {
        return false;
    }

    public function reset(): void {}

    public function toArray(): array
    {
        return ['max' => 1_000, 'used' => 0, 'reserved' => 0, 'available' => 1_000, 'percent' => 0.0];
    }
}

/** @param list<ToolCall> $calls */
function runLimitsToolRound(array $calls, ?Usage $usage = null): \Closure
{
    return fn(): array => [new Response(content: '', finishReason: ProviderFinishReason::ToolUse, toolCalls: $calls, model: 'test-model', usage: $usage)];
}

function runLimitsTextRound(string $text = 'Finished.', ?Usage $usage = null): \Closure
{
    return fn(): array => [new Response(content: $text, finishReason: ProviderFinishReason::Stop, model: 'test-model', usage: $usage)];
}

test('RunLimits adds the cumulative limits, off by default', function () {
    $limits = new RunLimits();

    expect($limits->maxRunSeconds)->toBe(0.0)
        ->and($limits->maxResponseBytes)->toBe(0)
        ->and($limits->maxEstimatedTokens)->toBe(0)
        ->and($limits->maxInputTokens)->toBe(0);
});

test('RunLimits rejects a negative cumulative limit', function (array $arguments) {
    new RunLimits(...$arguments);
})->throws(InvalidArgumentException::class)->with([
    'maxRunSeconds' => [['maxRunSeconds' => -0.5]],
    'maxResponseBytes' => [['maxResponseBytes' => -1]],
    'maxEstimatedTokens' => [['maxEstimatedTokens' => -1]],
    'maxInputTokens' => [['maxInputTokens' => -1]],
]);

test('a provider that reports zero usage still hits maxEstimatedTokens', function () {
    $zero = new Usage(promptTokens: 0, completionTokens: 0, totalTokens: 0);
    $provider = new RunLimitsScriptedProvider([], runLimitsToolRound([new ToolCall('c', 'first', [])], $zero));
    $events = new RunLimitsEventRecorder();
    $agent = new RunLimitsTestAgent(provider: $provider, maxIter: 25, runLimits: new RunLimits(maxEstimatedTokens: 1_000));
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Loop forever.'));

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Token limit reached.')
        ->and($events->dataFor('agent.error'))->toContain('Token limit reached.')
        ->and($provider->calls)->toBeGreaterThan(1)->toBeLessThan(25);
});

test('an oversized history is refused before dispatch', function () {
    $history = new Conversation();
    $history->add(new UserMessage(str_repeat('history ', 5_000)));
    $provider = new RunLimitsScriptedProvider([runLimitsTextRound()]);
    $agent = new RunLimitsTestAgent(provider: $provider, runLimits: new RunLimits(maxEstimatedTokens: 5_000));

    $output = $agent->run(new UserMessage('Hi'), $history);

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Token limit reached.')
        ->and($provider->calls)->toBe(0);
});

test('tool schemas count toward the request estimate', function () {
    $heavy = new Tool('heavy', str_repeat('A very long description. ', 1_600), [], fn(array $input): ToolResult => ToolResult::success('ok'));

    $light = new RunLimitsTestAgent(provider: $lightProvider = new RunLimitsScriptedProvider([runLimitsTextRound()]), runLimits: new RunLimits(maxEstimatedTokens: 5_000));
    $lightOutput = $light->run(new UserMessage('Hi'));

    $agent = new RunLimitsTestAgent(provider: $provider = new RunLimitsScriptedProvider([runLimitsTextRound()]), runLimits: new RunLimits(maxEstimatedTokens: 5_000));
    $agent->extraTools = [$heavy];
    $output = $agent->run(new UserMessage('Hi'));

    expect($lightOutput->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($lightProvider->calls)->toBe(1)
        ->and($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Token limit reached.')
        ->and($provider->calls)->toBe(0);
});

test('maxResponseBytes stops a stream mid-way', function () {
    $provider = new RunLimitsScriptedProvider([function (): Generator {
        for ($i = 0; $i < 1_000; $i++) {
            yield new Response(content: str_repeat('a', 1_024), finishReason: ProviderFinishReason::Stop, model: 'test-model');
        }
    }]);
    $events = new RunLimitsEventRecorder();
    $agent = new RunLimitsTestAgent(provider: $provider, runLimits: new RunLimits(maxResponseBytes: 10 * 1_024));
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Talk a lot.'));

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Response size limit reached.')
        ->and($events->dataFor('agent.error'))->toContain('Response size limit reached.')
        ->and($provider->chunksConsumed)->toBeLessThanOrEqual(11);
});

test('maxResponseBytes counts tool calls and content blocks', function () {
    $blocks = [['type' => 'thinking', 'thinking' => str_repeat('t', 2_000), 'signature' => 'sig']];
    $calls = [new ToolCall('c', 'first', ['payload' => str_repeat('p', 2_000)])];
    $provider = new RunLimitsScriptedProvider([fn(): array => [
        new Response(content: '', finishReason: ProviderFinishReason::ToolUse, toolCalls: $calls, model: 'test-model', contentBlocks: $blocks),
    ]]);
    $agent = new RunLimitsTestAgent(provider: $provider, runLimits: new RunLimits(maxResponseBytes: 3_000));

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->content)->toBe('Response size limit reached.')
        ->and($agent->ran)->toBe([]);
});

test('maxRunSeconds ends the run', function () {
    $provider = new RunLimitsScriptedProvider([function (): Generator {
        for ($i = 0; $i < 1_000; $i++) {
            usleep(20_000);
            yield new Response(content: 'tick ', finishReason: ProviderFinishReason::Stop, model: 'test-model');
        }
    }]);
    $events = new RunLimitsEventRecorder();
    $agent = new RunLimitsTestAgent(provider: $provider, runLimits: new RunLimits(maxRunSeconds: 0.01));
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Take your time.'));

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Run time limit reached.')
        ->and($events->dataFor('agent.error'))->toContain('Run time limit reached.')
        ->and($provider->chunksConsumed)->toBeLessThanOrEqual(2);
});

test('maxRunSeconds is checked before each serial tool execution', function () {
    $provider = new RunLimitsScriptedProvider([
        runLimitsToolRound([new ToolCall('c1', 'first', []), new ToolCall('c2', 'second', [])]),
        runLimitsTextRound(),
    ]);
    $agent = new RunLimitsTestAgent(
        provider: $provider,
        executionPolicy: new RunLimitsSlowPolicy(100_000),
        runLimits: new RunLimits(maxRunSeconds: 0.05),
    );

    $output = $agent->run(new UserMessage('Run both.'));

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Run time limit reached.')
        ->and($agent->ran)->toBe([])
        ->and(array_map(fn(ToolResult $r): string => $r->callId . ':' . $r->content, $output->toolResults))
        ->toBe(['c1:Run time limit reached.', 'c2:Run time limit reached.'])
        ->and($provider->calls)->toBe(1);
});

test('maxRunSeconds is checked before a batch call', function () {
    $provider = new RunLimitsScriptedProvider([
        runLimitsToolRound([new ToolCall('c1', 'first', []), new ToolCall('c2', 'second', [])]),
        runLimitsTextRound(),
    ]);
    $executor = new RunLimitsBatchExecutor();
    $agent = new RunLimitsTestAgent(
        provider: $provider,
        executionPolicy: new RunLimitsSlowPolicy(50_000),
        toolExecutor: $executor,
        runLimits: new RunLimits(maxRunSeconds: 0.05),
    );

    $output = $agent->run(new UserMessage('Run both.'));

    expect($output->content)->toBe('Run time limit reached.')
        ->and($executor->batches)->toBe(0)
        ->and($agent->ran)->toBe([])
        ->and($output->toolResults)->toHaveCount(2);
});

test('an oversized input never reaches the provider', function () {
    $provider = new RunLimitsScriptedProvider([runLimitsTextRound()]);
    $events = new RunLimitsEventRecorder();
    $agent = new RunLimitsTestAgent(provider: $provider, runLimits: new RunLimits(maxInputTokens: 10));
    $agent->attach($events);

    $output = $agent->run(new UserMessage(str_repeat('x', 400)));

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Input is too large.')
        ->and($events->dataFor('agent.error'))->toContain('Input is too large.')
        ->and($provider->calls)->toBe(0);
});

test('maxInputTokens measures only the new input, not the history', function () {
    $history = new Conversation();
    $history->add(new UserMessage(str_repeat('history ', 1_000)));
    $provider = new RunLimitsScriptedProvider([runLimitsTextRound()]);
    $agent = new RunLimitsTestAgent(provider: $provider, runLimits: new RunLimits(maxInputTokens: 10));

    $output = $agent->run(new UserMessage('Hi'), $history);

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($provider->calls)->toBe(1);
});

test('null usage leaves the estimate in force for the budget-exit threshold', function () {
    $window = new ContextWindow(maxTok: 1_000, reservedTok: 0);
    $provider = new RunLimitsScriptedProvider([runLimitsTextRound(str_repeat('y', 4_000))]);
    $events = new RunLimitsEventRecorder();
    $agent = new RunLimitsTestAgent(provider: $provider, contextWindow: $window, budgetExitThreshold: 0.5);
    $agent->attach($events);

    $agent->run(new UserMessage('Write a lot.'));

    expect($events->dataFor('agent.budget_warning'))->toHaveCount(1)
        ->and($window->usedTokens())->toBeGreaterThanOrEqual(1_000);
});

test('a custom under-reporting context window still triggers agent.budget_warning', function () {
    $provider = new RunLimitsScriptedProvider([runLimitsTextRound(str_repeat('y', 4_000), new Usage(totalTokens: 1))]);
    $events = new RunLimitsEventRecorder();
    $agent = new RunLimitsTestAgent(provider: $provider, contextWindow: new RunLimitsUnderReportingWindow(), budgetExitThreshold: 0.5);
    $agent->attach($events);

    $agent->run(new UserMessage('Write a lot.'));

    $warnings = $events->dataFor('agent.budget_warning');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['usagePercent'])->toBeGreaterThanOrEqual(50.0);
});
