<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Tests\Support\Agent\ScriptedEventsProvider;
use Tests\Support\Agent\ScriptedProvider;

function addTool(): Tool
{
    return new Tool(
        name: 'add',
        description: 'Add two numbers',
        parameters: [
            new NumberParameter('a', 'First number', required: true),
            new NumberParameter('b', 'Second number', required: true),
        ],
        callback: fn(array $args): ToolResult => ToolResult::success((string) ($args['a'] + $args['b'])),
    );
}

function toolThenAnswer(): ScriptedProvider
{
    return new ScriptedProvider([
        new Response(content: '', finishReason: ProviderFinishReason::ToolUse, toolCalls: [new ToolCall('c1', 'add', ['a' => 42, 'b' => 58])]),
        new Response(content: '100', finishReason: ProviderFinishReason::Stop),
    ]);
}

test('Agent runs with instructions and tools passed to the constructor', function () {
    $provider = toolThenAnswer();
    $agent = new Agent($provider, instructions: 'You are a calculator.', tools: [addTool()]);

    $output = $agent->run(new UserMessage('What is 42 + 58?'));

    $system = $provider->calls[0]['messages'][0];
    $toolResult = array_values(array_filter($provider->calls[1]['messages'], fn($m) => $m->role() === Role::Tool))[0];

    expect($agent)->toBeInstanceOf(AbstractAgent::class)
        ->and($agent->instructions())->toBe('You are a calculator.')
        ->and($provider->calls[0]['tools'])->toContain('add')
        ->and($system->content())->toContain('You are a calculator.')
        ->and($toolResult->content())->toContain('100')
        ->and($output->content)->toBe('100');
});

test('Agent has a default instruction', function () {
    expect((new Agent(new ScriptedProvider([])))->instructions())->not->toBe('');
});

test('addTool() adds a tool to any AbstractAgent, alongside tools()', function () {
    $provider = toolThenAnswer();
    $agent = new class($provider) extends AbstractAgent {
        public function instructions(): string
        {
            return 'Calculator.';
        }
    };

    expect($agent->addTool(addTool()))->toBe($agent);

    $output = $agent->run(new UserMessage('What is 42 + 58?'));

    expect($provider->calls[0]['tools'])->toContain('add')
        ->and($output->content)->toBe('100');
});

test('Agent passes runLimits, cancellationToken and providerOptions to AbstractAgent', function () {
    $provider = new ScriptedEventsProvider([[new TextDelta('partial'), new Finish(ProviderFinishReason::Stop)]]);
    // Cancels once the provider has been called, so the run reaches the stream first.
    $token = new class($provider) implements CancellationTokenInterface {
        public function __construct(private readonly ScriptedEventsProvider $provider) {}

        public function isCancelled(): bool
        {
            return $this->provider->calls > 0;
        }
    };
    $agent = new Agent(
        $provider,
        runLimits: new RunLimits(maxRunSeconds: 5),
        cancellationToken: $token,
        providerOptions: ['max_tokens' => 10],
    );

    $output = $agent->run(new UserMessage('Hi'));

    expect($provider->options)->toBe([['max_tokens' => 10]])
        ->and($output->finishReason)->toBe(AgentFinishReason::Cancelled);
});

test('Agent applies the RunLimits it is given', function () {
    $provider = new ScriptedEventsProvider([]);
    $agent = new Agent($provider, runLimits: new RunLimits(maxInputTokens: 1));

    $output = $agent->run(new UserMessage('A message far longer than one token.'));

    expect($output->finishReason)->toBe(AgentFinishReason::BudgetExhausted)
        ->and($output->content)->toBe('Input is too large.')
        ->and($provider->calls)->toBe(0);
});
