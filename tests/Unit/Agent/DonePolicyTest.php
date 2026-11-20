<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

// The built-in `done` call used to finish the run before the execution policy saw it, and a
// non-string `response` crashed the run. These tests pin that the policy authorizes `done` and
// that a malformed or duplicate `done` gets an error result paired to its call id.

final class DonePolicyProvider implements ProviderInterface
{
    use StreamsEventsFromResponses;

    public int $streams = 0;

    /** @param list<list<Response>> $rounds */
    public function __construct(private array $rounds) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('chat() is not used by AbstractAgent::run().');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->streams++;

        yield from array_shift($this->rounds) ?? throw new RuntimeException('No scripted stream left.');
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

final class DonePolicyRecorder implements ToolExecutionPolicyInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private true|string $decision) {}

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        $this->calls[] = [$toolName, $arguments];

        return $this->decision;
    }
}

final class DonePolicyAgent extends AbstractAgent
{
    /** @var array<string, int> */
    public array $ran = [];

    public function instructions(): string
    {
        return 'You are a done policy test agent.';
    }

    public function tools(): array
    {
        return [new Tool('ping', 'The ping tool.', [], function (array $input): ToolResult {
            $this->ran['ping'] = ($this->ran['ping'] ?? 0) + 1;

            return ToolResult::success('ping ran');
        })];
    }
}

/** @param list<ToolCall> $calls */
function doneToolRound(array $calls): array
{
    return [new Response(content: '', finishReason: ProviderFinishReason::ToolUse, toolCalls: $calls, model: 'test-model')];
}

function doneTextRound(string $text): array
{
    return [new Response(content: $text, finishReason: ProviderFinishReason::Stop, model: 'test-model')];
}

/** @param array<string, mixed> $arguments */
function doneCall(string $id, array $arguments): ToolCall
{
    return new ToolCall($id, 'done', $arguments);
}

function doneResultFor(Output $output, string $callId): ToolResult
{
    $found = array_values(array_filter($output->toolResults, fn(ToolResult $r): bool => $r->callId === $callId));
    expect($found)->toHaveCount(1);

    return $found[0];
}

test('a deny-all policy is consulted for done and the run does not finish with attacker text', function () {
    $policy = new DonePolicyRecorder('no finishing');
    $provider = new DonePolicyProvider([doneToolRound([doneCall('d1', ['response' => 'attacker text'])]), doneTextRound('ok')]);
    $agent = new DonePolicyAgent(provider: $provider, executionPolicy: $policy);

    $output = $agent->run(new UserMessage('Hi'));

    expect(count($policy->calls))->toBeGreaterThanOrEqual(1)
        ->and($policy->calls[0])->toBe(['done', ['response' => 'attacker text']])
        ->and($output->content)->toBe('ok')
        ->and($output->finishReason)->toBe(AgentFinishReason::Stop);

    $result = doneResultFor($output, 'd1');
    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('Denied by policy: no finishing');
});

test('a denied done leaves a valid assistant turn and runs the other calls once', function () {
    $policy = new class implements ToolExecutionPolicyInterface {
        /** @var list<string> */
        public array $seen = [];

        public function shouldExecute(string $toolName, array $arguments): true|string
        {
            $this->seen[] = $toolName;

            return $toolName === 'done' ? 'no' : true;
        }
    };
    $provider = new DonePolicyProvider([
        doneToolRound([new ToolCall('p1', 'ping', []), doneCall('d1', ['response' => 'x']), doneCall('d2', ['response' => 'y'])]),
        doneTextRound('ok'),
    ]);
    $agent = new DonePolicyAgent(provider: $provider, executionPolicy: $policy);

    $output = $agent->run(new UserMessage('Hi'));

    expect($policy->seen)->toBe(['done', 'ping'])
        ->and($agent->ran)->toBe(['ping' => 1])
        ->and(doneResultFor($output, 'p1')->content)->toBe('ping ran')
        ->and(doneResultFor($output, 'd1')->content)->toBe('Denied by policy: no')
        ->and(doneResultFor($output, 'd2')->content)->toBe('Only one done call is allowed per response.')
        ->and($output->content)->toBe('ok');

    $assistantTurns = array_values(array_filter(
        $output->conversation->messages(),
        fn($m): bool => $m instanceof AssistantMessage && $m->toolCalls() !== [],
    ));
    $toolMessages = array_values(array_filter($output->conversation->messages(), fn($m): bool => $m instanceof ToolResultMessage));
    expect($assistantTurns)->toHaveCount(1)
        ->and($assistantTurns[0]->toolCalls())->toHaveCount(3)
        ->and(array_map(fn(ToolResultMessage $m): ?string => $m->result()->callId, $toolMessages))->toBe(['p1', 'd1', 'd2']);
});

test('an allowing policy sees done once and the run finishes Done', function () {
    $policy = new DonePolicyRecorder(true);
    $provider = new DonePolicyProvider([doneToolRound([
        doneCall('d1', ['response' => 'All set.']),
        new ToolCall('p1', 'ping', []),
        doneCall('d2', ['response' => 'second']),
    ])]);
    $agent = new DonePolicyAgent(provider: $provider, executionPolicy: $policy);

    $output = $agent->run(new UserMessage('Hi'));

    expect($policy->calls)->toBe([['done', ['response' => 'All set.']]])
        ->and($agent->ran)->toBe([])
        ->and($output->content)->toBe('All set.')
        ->and($output->finishReason)->toBe(AgentFinishReason::Done);
});

test('a response with 1000 done calls makes exactly one policy call', function () {
    $policy = new DonePolicyRecorder('no');
    $calls = [];
    for ($n = 0; $n < 1000; $n++) {
        $calls[] = doneCall("d{$n}", ['response' => "r{$n}"]);
    }
    $provider = new DonePolicyProvider([doneToolRound($calls), doneTextRound('ok')]);
    $agent = new DonePolicyAgent(provider: $provider, executionPolicy: $policy);

    $output = $agent->run(new UserMessage('Hi'));

    expect($policy->calls)->toHaveCount(1)
        ->and(doneResultFor($output, 'd0')->content)->toBe('Denied by policy: no')
        ->and(doneResultFor($output, 'd999')->content)->toBe('Only one done call is allowed per response.')
        ->and($output->content)->toBe('ok');
});

test('done with a non-string response does not throw', function (mixed $response) {
    $provider = new DonePolicyProvider([doneToolRound([doneCall('d1', ['response' => $response])]), doneTextRound('ok')]);
    $agent = new DonePolicyAgent(provider: $provider);

    $output = $agent->run(new UserMessage('Hi'));

    $result = doneResultFor($output, 'd1');
    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('Parameter "response" must be a string.')
        ->and($output->content)->toBe('ok')
        ->and($output->finishReason)->toBe(AgentFinishReason::Stop);
})->with([
    'array' => [['nested' => 'value']],
    'int' => [42],
    'null' => [null],
]);

test('done without a response is malformed', function () {
    $provider = new DonePolicyProvider([doneToolRound([doneCall('d1', [])]), doneTextRound('ok')]);
    $agent = new DonePolicyAgent(provider: $provider);

    $output = $agent->run(new UserMessage('Hi'));

    expect(doneResultFor($output, 'd1')->content)->toBe('Parameter "response" must be a string.')
        ->and($output->finishReason)->toBe(AgentFinishReason::Stop);
});

test('done with unknown keys is rejected like any tool', function () {
    $provider = new DonePolicyProvider([doneToolRound([doneCall('d1', ['response' => 'hi', 'zeta' => 1, 'alpha' => 2])]), doneTextRound('ok')]);
    $agent = new DonePolicyAgent(provider: $provider);

    $output = $agent->run(new UserMessage('Hi'));

    expect(doneResultFor($output, 'd1')->content)->toBe('Unknown parameters: alpha, zeta. Allowed: response.')
        ->and($output->content)->toBe('ok');
});

test('a malformed done error is redacted at the post-flight boundary', function () {
    $secret = 'sk-' . str_repeat('a', 40);
    $provider = new DonePolicyProvider([doneToolRound([doneCall('d1', ['response' => 'hi', $secret => 1])]), doneTextRound('ok')]);
    $agent = new DonePolicyAgent(provider: $provider);

    $output = $agent->run(new UserMessage('Hi'));

    expect(doneResultFor($output, 'd1')->content)->not->toContain($secret);
});

test('without a policy done finishes as before', function () {
    $provider = new DonePolicyProvider([doneToolRound([doneCall('d1', ['response' => 'All set.'])])]);
    $agent = new DonePolicyAgent(provider: $provider);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->content)->toBe('All set.')
        ->and($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($output->toolResults)->toBe([])
        ->and($provider->streams)->toBe(1);
});
