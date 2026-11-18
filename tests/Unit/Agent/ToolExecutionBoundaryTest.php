<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolGrantInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

// maxTools used to cap only what the provider saw: a model that named a hidden tool still ran it.
// These tests pin the execution boundary (advertised + granted tools only) and the per-turn and
// per-run tool-call caps from RunLimits.

/**
 * Scripted provider: each stream() call yields the next list of chunks and records the tool names sent.
 */
final class BoundaryScriptedProvider implements ProviderInterface
{
    /** @var list<list<string>> */
    public array $advertised = [];

    /** @param list<list<Response>> $streams */
    public function __construct(private array $streams) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('chat() is not used by AbstractAgent::run().');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->advertised[] = array_map(fn(ToolInterface $t): string => $t->name(), $tools);

        yield from array_shift($this->streams) ?? throw new RuntimeException('No scripted stream left.');
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

/** A discovery tool that grants names after it runs, like a host's tool_search. */
final class BoundaryGrantTool implements ToolInterface, ToolGrantInterface
{
    public int $grantReads = 0;

    /** @param list<string> $grants */
    public function __construct(private array $grants, private ToolResult $result) {}

    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'Find tools.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        return $this->result;
    }

    public function toFunctionSchema(): array
    {
        return ['type' => 'function', 'function' => ['name' => 'search', 'description' => 'Find tools.', 'parameters' => ['type' => 'object', 'properties' => []]]];
    }

    public function grantedToolNames(): array
    {
        $this->grantReads++;

        return $this->grants;
    }
}

final class BoundaryDenyPolicy implements ToolExecutionPolicyInterface
{
    /** @var list<string> */
    public array $seen = [];

    /** @param list<string> $deny */
    public function __construct(private array $deny = []) {}

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        $this->seen[] = $toolName;

        return in_array($toolName, $this->deny, true) ? 'not allowed' : true;
    }
}

final class BoundaryEventRecorder implements SplObserver
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

final class BoundaryTestAgent extends AbstractAgent
{
    /** @var array<string, int> */
    public array $ran = [];

    /** @var list<ToolInterface> */
    public array $extraTools = [];

    public function instructions(): string
    {
        return 'You are a tool boundary test agent.';
    }

    public function tools(): array
    {
        $tools = $this->extraTools;
        foreach (['ping', 'secret'] as $name) {
            $tools[] = new Tool($name, "The {$name} tool.", [], function (array $input) use ($name): ToolResult {
                $this->ran[$name] = ($this->ran[$name] ?? 0) + 1;

                return ToolResult::success("{$name} ran");
            });
        }

        return $tools;
    }
}

/** @param list<ToolCall> $calls */
function boundaryToolRound(array $calls): array
{
    return [new Response(content: '', finishReason: ProviderFinishReason::ToolUse, toolCalls: $calls, model: 'test-model')];
}

function boundaryTextRound(string $text = 'Finished.'): array
{
    return [new Response(content: $text, finishReason: ProviderFinishReason::Stop, model: 'test-model')];
}

function boundaryCall(string $id, string $name): ToolCall
{
    return new ToolCall($id, $name, []);
}

/** @return list<ToolResult> */
function boundaryResultsFor(Output $output, string $callId): array
{
    return array_values(array_filter($output->toolResults, fn(ToolResult $r): bool => $r->callId === $callId));
}

test('a tool hidden by maxTools is not executed', function () {
    $provider = new BoundaryScriptedProvider([boundaryToolRound([boundaryCall('c1', 'secret')]), boundaryTextRound()]);
    $agent = new BoundaryTestAgent(provider: $provider);
    $agent->setMaxTools(1);

    $output = $agent->run(new UserMessage('Hi'));

    expect($provider->advertised[0])->toBe(['done'])
        ->and($agent->ran)->toBe([])
        ->and($output->toolResults)->toHaveCount(1)
        ->and($output->toolResults[0]->status)->toBe(ToolResultStatus::Error)
        ->and($output->toolResults[0]->content)->toBe('Unknown tool: secret')
        ->and($output->toolResults[0]->callId)->toBe('c1')
        ->and($output->finishReason)->toBe(AgentFinishReason::Stop);
});

test('the policy still sees a call to a hidden tool first', function () {
    $policy = new BoundaryDenyPolicy();
    $provider = new BoundaryScriptedProvider([boundaryToolRound([boundaryCall('c1', 'secret')]), boundaryTextRound()]);
    $agent = new BoundaryTestAgent(provider: $provider, executionPolicy: $policy);
    $agent->setMaxTools(1);

    $output = $agent->run(new UserMessage('Hi'));

    expect($policy->seen)->toBe(['secret'])
        ->and($output->toolResults[0]->content)->toBe('Unknown tool: secret');
});

test('executeUnadvertisedTools restores the old behaviour', function () {
    $provider = new BoundaryScriptedProvider([boundaryToolRound([boundaryCall('c1', 'secret')]), boundaryTextRound()]);
    $agent = new BoundaryTestAgent(provider: $provider, runLimits: new RunLimits(executeUnadvertisedTools: true));
    $agent->setMaxTools(1);

    $output = $agent->run(new UserMessage('Hi'));

    expect($agent->ran)->toBe(['secret' => 1])
        ->and($output->toolResults[0]->content)->toBe('secret ran');
});

test('a ToolGrantInterface tool can grant a hidden tool for the rest of the run', function () {
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'search')]),
        boundaryToolRound([boundaryCall('c2', 'secret')]),
        boundaryToolRound([boundaryCall('c3', 'secret')]),
        boundaryTextRound(),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider);
    $agent->extraTools = [new BoundaryGrantTool(['secret'], ToolResult::success('found secret'))];
    $agent->setMaxTools(2);

    $output = $agent->run(new UserMessage('Hi'));

    expect($provider->advertised[0])->toBe(['search', 'done'])
        ->and($agent->ran)->toBe(['secret' => 2])
        ->and(boundaryResultsFor($output, 'c3')[0]->content)->toBe('secret ran');
});

test('a granted name that does not exist grants nothing', function () {
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'search')]),
        boundaryToolRound([boundaryCall('c2', 'nope'), boundaryCall('c3', 'secret')]),
        boundaryTextRound(),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider);
    $agent->extraTools = [new BoundaryGrantTool(['nope'], ToolResult::success('found nope'))];
    $agent->setMaxTools(2);

    $output = $agent->run(new UserMessage('Hi'));

    expect($agent->ran)->toBe([])
        ->and(boundaryResultsFor($output, 'c2')[0]->content)->toBe('Unknown tool: nope')
        ->and(boundaryResultsFor($output, 'c3')[0]->content)->toBe('Unknown tool: secret');
});

test('a grant tool denied by the policy grants nothing', function () {
    $grant = new BoundaryGrantTool(['secret'], ToolResult::success('found secret'));
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'search')]),
        boundaryToolRound([boundaryCall('c2', 'secret')]),
        boundaryTextRound(),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider, executionPolicy: new BoundaryDenyPolicy(['search']));
    $agent->extraTools = [$grant];
    $agent->setMaxTools(2);

    $output = $agent->run(new UserMessage('Hi'));

    expect($grant->grantReads)->toBe(0)
        ->and($agent->ran)->toBe([])
        ->and(boundaryResultsFor($output, 'c2')[0]->content)->toBe('Unknown tool: secret');
});

test('a grant tool that returns an error result grants nothing', function () {
    $grant = new BoundaryGrantTool(['secret'], ToolResult::error('search failed'));
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'search')]),
        boundaryToolRound([boundaryCall('c2', 'secret')]),
        boundaryTextRound(),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider);
    $agent->extraTools = [$grant];
    $agent->setMaxTools(2);

    $output = $agent->run(new UserMessage('Hi'));

    expect($grant->grantReads)->toBe(0)
        ->and($agent->ran)->toBe([])
        ->and(boundaryResultsFor($output, 'c2')[0]->content)->toBe('Unknown tool: secret');
});

test('a grant tool refused by the per-turn cap grants nothing', function () {
    $grant = new BoundaryGrantTool(['secret'], ToolResult::success('found secret'));
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'ping'), boundaryCall('c2', 'search')]),
        boundaryToolRound([boundaryCall('c3', 'secret')]),
        boundaryTextRound(),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider, runLimits: new RunLimits(maxToolCallsPerTurn: 1));
    $agent->extraTools = [$grant];
    $agent->setMaxTools(3);

    $output = $agent->run(new UserMessage('Hi'));

    expect($provider->advertised[0])->toBe(['search', 'ping', 'done'])
        ->and($grant->grantReads)->toBe(0)
        ->and($agent->ran)->toBe(['ping' => 1])
        ->and(boundaryResultsFor($output, 'c3')[0]->content)->toBe('Unknown tool: secret');
});

test('a second run starts without the first run\'s grants', function () {
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'search')]),
        boundaryToolRound([boundaryCall('c2', 'secret')]),
        boundaryTextRound(),
        boundaryToolRound([boundaryCall('c3', 'secret')]),
        boundaryTextRound(),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider);
    $agent->extraTools = [new BoundaryGrantTool(['secret'], ToolResult::success('found secret'))];
    $agent->setMaxTools(2);

    $agent->run(new UserMessage('First'));
    $second = $agent->run(new UserMessage('Second'));

    expect($agent->ran)->toBe(['secret' => 1])
        ->and(boundaryResultsFor($second, 'c3')[0]->content)->toBe('Unknown tool: secret');
});

test('calls beyond maxToolCallsPerTurn are not executed and get a limit result', function () {
    $policy = new BoundaryDenyPolicy();
    $recorder = new BoundaryEventRecorder();
    $calls = array_map(fn(int $n): ToolCall => boundaryCall("c{$n}", 'ping'), range(1, 5));
    $provider = new BoundaryScriptedProvider([boundaryToolRound($calls), boundaryTextRound()]);
    $agent = new BoundaryTestAgent(provider: $provider, executionPolicy: $policy, runLimits: new RunLimits(maxToolCallsPerTurn: 2));
    $agent->attach($recorder);

    $output = $agent->run(new UserMessage('Hi'));

    expect($agent->ran)->toBe(['ping' => 2])
        ->and($policy->seen)->toBe(['ping', 'ping'])
        ->and($output->toolResults)->toHaveCount(5)
        ->and(array_map(fn(ToolResult $r): ?string => $r->callId, $output->toolResults))->toBe(['c1', 'c2', 'c3', 'c4', 'c5'])
        ->and(array_map(fn(ToolResult $r): string => $r->content, array_slice($output->toolResults, 2)))
        ->toBe(array_fill(0, 3, 'Tool call limit reached: at most 2 calls per turn.'))
        ->and($output->toolResults[4]->status)->toBe(ToolResultStatus::Error)
        ->and($recorder->dataFor('agent.warning'))->toHaveCount(1)
        ->and($output->finishReason)->toBe(AgentFinishReason::Stop);
});

test('maxToolCallsPerRun ends the run as BudgetExhausted', function () {
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'ping'), boundaryCall('c2', 'ping')]),
        boundaryToolRound([boundaryCall('c3', 'ping'), boundaryCall('c4', 'ping')]),
        boundaryTextRound(),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider, runLimits: new RunLimits(maxToolCallsPerRun: 3));

    $output = $agent->run(new UserMessage('Hi'));

    expect($agent->ran)->toBe(['ping' => 3])
        ->and($output->toolResults)->toHaveCount(4)
        ->and($output->toolResults[3]->callId)->toBe('c4')
        ->and($output->toolResults[3]->content)->toBe('Tool call limit reached: at most 3 calls per run.')
        ->and($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Tool call limit reached for this run.')
        ->and($output->iterations)->toBe(2);
});

test('done counts toward neither cap', function () {
    $provider = new BoundaryScriptedProvider([
        boundaryToolRound([boundaryCall('c1', 'ping')]),
        boundaryToolRound([new ToolCall('c2', 'done', ['response' => 'All done.'])]),
    ]);
    $agent = new BoundaryTestAgent(provider: $provider, runLimits: new RunLimits(maxToolCallsPerTurn: 1, maxToolCallsPerRun: 1));

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($output->content)->toBe('All done.');
});

test('negative limits are rejected', function (array $arguments) {
    expect(fn() => new RunLimits(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'per turn' => [['maxToolCallsPerTurn' => -1]],
    'per run' => [['maxToolCallsPerRun' => -1]],
]);

test('RunLimits defaults to no caps and the advertised boundary', function () {
    $limits = new RunLimits();

    expect($limits->maxToolCallsPerTurn)->toBe(0)
        ->and($limits->maxToolCallsPerRun)->toBe(0)
        ->and($limits->executeUnadvertisedTools)->toBeFalse();
});
