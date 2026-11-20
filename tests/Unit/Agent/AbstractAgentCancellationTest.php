<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\CallbackObserver;
use CarmeloSantana\PHPAgents\Agent\Event\DonePreview;
use CarmeloSantana\PHPAgents\Agent\Event\RunFinish;
use CarmeloSantana\PHPAgents\Agent\Event\ToolInputDiscarded;
use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Tests\Support\Agent\ScriptedEventsProvider;

final class CancellationTestToken implements CancellationTokenInterface
{
    public bool $cancelled = false;

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}

final class CancellationTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a cancellation test agent.';
    }

    public function tools(): array
    {
        return [
            new Tool('echo', 'Echo text.', [new StringParameter('text', 'Text to echo')], fn(array $input): ToolResult => ToolResult::success('echo: ' . ($input['text'] ?? ''))),
        ];
    }
}

/**
 * Runs a batch inline, every entry to the end, whatever the token says meanwhile.
 */
final class CancellationTestBatchExecutor implements BatchToolExecutorInterface
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

final class CancellationTestRecorder implements SplObserver
{
    /** @var list<array{string, mixed}> */
    public array $events = [];

    public function update(SplSubject $subject): void
    {
        /** @var AbstractAgent $subject */
        $this->events[] = [$subject->lastEvent(), $subject->lastEventData()];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn(array $e): string => $e[0], $this->events);
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

/**
 * @param list<array{string, string, array<string, mixed>}> $calls id, name, arguments
 * @return list<StreamEvent>
 */
function cancellationToolRound(array $calls): array
{
    $events = [];
    foreach ($calls as $index => [$id, $name, $arguments]) {
        $events[] = new ToolInputStart($index, $id, $name);
        $events[] = new ToolInputDelta($index, $id, (string) json_encode((object) $arguments));
        $events[] = new ToolInputEnd($index, $id, $name, $arguments);
    }
    $events[] = new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model');

    return $events;
}

/**
 * An agent over scripted events whose token flips when $cancelWhen first returns true for an
 * event. The flip observer is attached before the recorder.
 *
 * @param list<list<StreamEvent>> $scripts
 * @param \Closure(string, mixed): bool $cancelWhen
 * @param array<string, mixed> $arguments Named AbstractAgent constructor arguments
 * @return array{CancellationTestAgent, CancellationTestRecorder, ScriptedEventsProvider, CancellationTestToken}
 */
function cancellationAgent(array $scripts, \Closure $cancelWhen, array $arguments = []): array
{
    $provider = new ScriptedEventsProvider($scripts);
    $token = new CancellationTestToken();
    $agent = new CancellationTestAgent(...['provider' => $provider, 'cancellationToken' => $token, ...$arguments]);
    $agent->attach(new CallbackObserver(function (string $event, mixed $data) use ($token, $cancelWhen): void {
        if ($cancelWhen($event, $data)) {
            $token->cancelled = true;
        }
    }));
    $recorder = new CancellationTestRecorder();
    $agent->attach($recorder);

    return [$agent, $recorder, $provider, $token];
}

function cancelledBeforeItRan(string $callId): ToolResult
{
    return new ToolResult(ToolResultStatus::Error, 'Cancelled before it ran.', callId: $callId, errorCode: 'cancelled');
}

test('a cancel on a Tick mid-text returns the streamed text as Cancelled and abandons the stream', function () {
    [$agent, $events, $provider] = cancellationAgent(
        [[new TextDelta('Hello '), new TextDelta('wor'), new Tick(), new TextDelta('ld'), new Finish(ProviderFinishReason::Stop, 'stop', null, 'test-model')]],
        fn(string $event, mixed $data): bool => $event === 'agent.text_delta' && $data === 'wor',
    );

    $output = $agent->run(new UserMessage('Say hello world.'));
    $messages = $output->conversation?->messages() ?? [];
    $last = end($messages);

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Hello wor')
        ->and($output->providerFinishReason)->toBeNull()
        ->and($last)->toBeInstanceOf(AssistantMessage::class)
        ->and($last->content())->toBe('Hello wor')
        ->and($last->toolCalls())->toBe([])
        ->and($events->names())->toBe([
            'agent.start',
            'agent.iteration',
            'agent.text_delta',
            'agent.text_delta',
            'agent.error',
            'agent.finish',
        ])
        ->and($events->dataFor('agent.error'))->toBe(['Task cancelled'])
        ->and($events->dataFor('agent.finish'))->toEqual([new RunFinish(AgentFinishReason::Cancelled, $output->usage ?? throw new LogicException(), 1, '')])
        ->and($provider->closedStreams)->toBe(1);
});

test('a cancel before any text returns the cancelled notice and appends no assistant message', function () {
    [$agent, $events, $provider] = cancellationAgent(
        [[new Tick(), new TextDelta('Hi'), new Finish(ProviderFinishReason::Stop, 'stop', null, 'test-model')]],
        fn(string $event): bool => $event === 'agent.iteration',
    );

    $input = new UserMessage('Say hi.');
    $output = $agent->run($input);
    $messages = $output->conversation?->messages() ?? [];

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Task was cancelled.')
        ->and(end($messages))->toBe($input)
        ->and($events->names())->toBe(['agent.start', 'agent.iteration', 'agent.error', 'agent.finish'])
        ->and($provider->closedStreams)->toBe(1);
});

test('a cancel while a tool call streams discards every call of the response and runs none', function () {
    [$agent, $events] = cancellationAgent(
        [[
            new ToolInputStart(0, 'c1', 'echo'),
            new ToolInputDelta(0, 'c1', '{"text":"a"}'),
            new ToolInputEnd(0, 'c1', 'echo', ['text' => 'a']),
            new ToolInputStart(1, 'c2', 'echo'),
            new ToolInputDelta(1, 'c2', '{"te'),
            new ToolInputDelta(1, 'c2', 'xt":"b"}'),
            new ToolInputEnd(1, 'c2', 'echo', ['text' => 'b']),
            new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
        ]],
        fn(string $event, mixed $data): bool => $event === 'agent.tool_input_delta' && $data->id === 'c2',
    );

    $input = new UserMessage('Echo a and b.');
    $output = $agent->run($input);
    $messages = $output->conversation?->messages() ?? [];

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Task was cancelled.')
        ->and($output->toolResults)->toBe([])
        ->and(end($messages))->toBe($input)
        ->and($events->names())->not->toContain('agent.tool_call')
        ->and($events->names())->not->toContain('agent.tool_result')
        ->and(array_slice($events->names(), -4))->toBe(['agent.tool_input_discarded', 'agent.tool_input_discarded', 'agent.error', 'agent.finish'])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([
            new ToolInputDiscarded(0, 'c1', 'echo', 'cancelled'),
            new ToolInputDiscarded(1, 'c2', 'echo', 'cancelled'),
        ]);
});

test('a cancel after Finish(ToolUse) before the first tool answers every call as cancelled', function () {
    [$agent, $events, $provider] = cancellationAgent(
        [cancellationToolRound([['c1', 'echo', ['text' => 'a']], ['c2', 'echo', ['text' => 'b']]])],
        fn(string $event): bool => $event === 'agent.step_finish',
    );

    $output = $agent->run(new UserMessage('Echo a and b.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Task was cancelled.')
        ->and($output->toolResults)->toEqual([cancelledBeforeItRan('c1'), cancelledBeforeItRan('c2')])
        ->and($provider->calls)->toBe(1)
        ->and($events->names())->not->toContain('agent.tool_call')
        ->and($events->names())->not->toContain('agent.tool_input_discarded')
        ->and(array_slice($events->names(), -5))->toBe(['agent.step_finish', 'agent.tool_result', 'agent.tool_result', 'agent.error', 'agent.finish'])
        ->and($events->dataFor('agent.tool_result'))->toEqual([cancelledBeforeItRan('c1'), cancelledBeforeItRan('c2')]);
});

test('a cancel between two serial tools keeps the first result and answers the second as cancelled', function () {
    [$agent, $events, $provider, $token] = cancellationAgent(
        [cancellationToolRound([['c1', 'flip', []], ['c2', 'echo', ['text' => 'b']]])],
        fn(): bool => false,
    );
    $agent->addTool(new Tool('flip', 'Cancel the run.', [], function () use ($token): ToolResult {
        $token->cancelled = true;

        return ToolResult::success('flipped');
    }));

    $output = $agent->run(new UserMessage('Flip, then echo b.'));
    $repaired = $output->conversation?->repairToolPairing()->messages() ?? [];
    $toolMessages = array_values(array_filter($repaired, fn($m): bool => $m instanceof ToolResultMessage));
    $toolCallMessages = array_values(array_filter($repaired, fn($m): bool => $m instanceof AssistantMessage && $m->toolCalls() !== []));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->toolResults)->toEqual([ToolResult::success('flipped')->withCallId('c1'), cancelledBeforeItRan('c2')])
        ->and($provider->calls)->toBe(1)
        ->and($toolCallMessages)->toHaveCount(1)
        ->and(array_map(fn(ToolCall $call): string => $call->id, $toolCallMessages[0]->toolCalls()))->toBe(['c1', 'c2'])
        ->and(array_map(fn(ToolResultMessage $m): ?string => $m->toolCallId(), $toolMessages))->toBe(['c1', 'c2'])
        ->and(array_slice($events->names(), -2))->toBe(['agent.error', 'agent.finish']);
});

test('a cancel during a batch lets the batch finish, keeps its results and ends the run', function () {
    $executor = new CancellationTestBatchExecutor();
    [$agent, $events, $provider, $token] = cancellationAgent(
        [cancellationToolRound([['c1', 'flip', []], ['c2', 'echo', ['text' => 'b']]]), [new TextDelta('Not reached.'), new Finish(ProviderFinishReason::Stop, 'stop', null, 'test-model')]],
        fn(): bool => false,
        ['toolExecutor' => $executor],
    );
    $agent->addTool(new Tool('flip', 'Cancel the run.', [], function () use ($token): ToolResult {
        $token->cancelled = true;

        return ToolResult::success('flipped');
    }));

    $output = $agent->run(new UserMessage('Flip and echo b.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($executor->batches)->toBe(1)
        ->and($output->toolResults)->toEqual([ToolResult::success('flipped')->withCallId('c1'), ToolResult::success('echo: b')->withCallId('c2')])
        ->and($provider->calls)->toBe(1)
        ->and(array_slice($events->names(), -5))->toBe(['agent.batch_end', 'agent.tool_result', 'agent.tool_result', 'agent.error', 'agent.finish']);
});

test('a cancel before a batch starts answers every batched call as cancelled', function () {
    $executor = new CancellationTestBatchExecutor();
    [$agent] = cancellationAgent(
        [cancellationToolRound([['c1', 'echo', ['text' => 'a']], ['c2', 'echo', ['text' => 'b']]])],
        fn(string $event, mixed $data): bool => $event === 'agent.tool_call' && $data->id === 'c2',
        ['toolExecutor' => $executor],
    );

    $output = $agent->run(new UserMessage('Echo a and b.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($executor->batches)->toBe(0)
        ->and($output->toolResults)->toEqual([cancelledBeforeItRan('c1'), cancelledBeforeItRan('c2')]);
});

test('a cancel at the top of an iteration returns Cancelled without calling the provider', function () {
    [$agent, $events, $provider, $token] = cancellationAgent([], fn(): bool => false);
    $token->cancelled = true;

    $output = $agent->run(new UserMessage('Hello.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Task was cancelled.')
        ->and($provider->calls)->toBe(0)
        ->and($events->names())->toBe(['agent.start', 'agent.error', 'agent.finish'])
        ->and($events->dataFor('agent.error'))->toBe(['Task cancelled']);
});

test('an observer filtered to agent.error still receives Task cancelled', function () {
    [$agent] = cancellationAgent(
        [[new TextDelta('Hel'), new Tick(), new Finish(ProviderFinishReason::Stop, 'stop', null, 'test-model')]],
        fn(string $event): bool => $event === 'agent.text_delta',
    );
    $errors = [];
    $agent->attach(new CallbackObserver(function (string $event, mixed $data) use (&$errors): void {
        $errors[] = $data;
    }, 'agent.error'));

    $agent->run(new UserMessage('Hello.'));

    expect($errors)->toBe(['Task cancelled']);
});

test('a cancel mid-preview returns the streamed text plus the done preview and discards the done call', function () {
    [$agent, $events, $provider] = cancellationAgent(
        [[
            new TextDelta('Wrapping up.'),
            new ToolInputStart(0, 'd1', 'done'),
            new ToolInputDelta(0, 'd1', '{"response":"The ans'),
            new Tick(),
            new ToolInputDelta(0, 'd1', 'wer is 4."}'),
            new ToolInputEnd(0, 'd1', 'done', ['response' => 'The answer is 4.']),
            new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
        ]],
        fn(string $event, mixed $data): bool => $event === 'agent.done_delta',
    );

    $output = $agent->run(new UserMessage('2+2?'));
    $messages = $output->conversation?->messages() ?? [];
    $last = end($messages);

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe("Wrapping up.\n\nThe ans")
        ->and($last)->toBeInstanceOf(AssistantMessage::class)
        ->and($last->content())->toBe("Wrapping up.\n\nThe ans")
        ->and($last->toolCalls())->toBe([])
        ->and($events->dataFor('agent.done_delta'))->toEqual([new DonePreview('d1', 'The ans')])
        ->and($events->dataFor('agent.done'))->toBe([])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'd1', 'done', 'cancelled')])
        ->and(array_slice($events->names(), -3))->toBe(['agent.tool_input_discarded', 'agent.error', 'agent.finish'])
        ->and($provider->closedStreams)->toBe(1);
});

test('a cancel mid-preview with no streamed text returns the done preview alone', function () {
    [$agent] = cancellationAgent(
        [[
            new ToolInputStart(0, 'd1', 'done'),
            new ToolInputDelta(0, 'd1', '{"response":"The ans'),
            new ToolInputDelta(0, 'd1', 'wer is 4."}'),
            new ToolInputEnd(0, 'd1', 'done', ['response' => 'The answer is 4.']),
            new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
        ]],
        fn(string $event): bool => $event === 'agent.done_delta',
    );

    $output = $agent->run(new UserMessage('2+2?'));
    $messages = $output->conversation?->messages() ?? [];

    expect($output->content)->toBe('The ans')
        ->and(end($messages)->content())->toBe('The ans');
});

test('a provider that throws once the token is set ends the run as Cancelled with the streamed text', function () {
    [$agent, $events, $provider] = cancellationAgent(
        [[new TextDelta('Hel'), new RuntimeException('Request cancelled by transport'), new TextDelta('lo')]],
        fn(string $event): bool => $event === 'agent.text_delta',
    );

    $output = $agent->run(new UserMessage('Say hello.'));
    $messages = $output->conversation?->messages() ?? [];
    $last = end($messages);

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Hel')
        ->and($last)->toBeInstanceOf(AssistantMessage::class)
        ->and($last->content())->toBe('Hel')
        ->and($events->names())->toBe(['agent.start', 'agent.iteration', 'agent.text_delta', 'agent.error', 'agent.finish'])
        ->and($events->dataFor('agent.error'))->toBe(['Task cancelled'])
        ->and($provider->closedStreams)->toBe(1);
});

test('a provider that throws once the token is set discards open calls as cancelled and keeps the done preview', function () {
    [$agent, $events] = cancellationAgent(
        [[
            new TextDelta('Wrapping up.'),
            new ToolInputStart(0, 'c1', 'echo'),
            new ToolInputDelta(0, 'c1', '{"text":"a"}'),
            new ToolInputEnd(0, 'c1', 'echo', ['text' => 'a']),
            new ToolInputStart(1, 'd1', 'done'),
            new ToolInputDelta(1, 'd1', '{"response":"The ans'),
            new RuntimeException('Request cancelled by transport'),
        ]],
        fn(string $event): bool => $event === 'agent.done_delta',
    );

    $output = $agent->run(new UserMessage('2+2?'));
    $messages = $output->conversation?->messages() ?? [];

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe("Wrapping up.\n\nThe ans")
        ->and(end($messages)->content())->toBe("Wrapping up.\n\nThe ans")
        ->and($output->toolResults)->toBe([])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([
            new ToolInputDiscarded(0, 'c1', 'echo', 'cancelled'),
            new ToolInputDiscarded(1, 'd1', 'done', 'cancelled'),
        ])
        ->and($events->dataFor('agent.error'))->toBe(['Task cancelled']);
});

test('a cancel on agent.step_finish of a text response returns the text as Cancelled', function () {
    [$agent, $events] = cancellationAgent(
        [[new TextDelta('Hello'), new Finish(ProviderFinishReason::Stop, 'stop', null, 'test-model')]],
        fn(string $event): bool => $event === 'agent.step_finish',
    );

    $output = $agent->run(new UserMessage('Say hello.'));
    $messages = $output->conversation?->messages() ?? [];
    $last = end($messages);

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Hello')
        ->and($output->model)->toBe('test-model')
        ->and($last)->toBeInstanceOf(AssistantMessage::class)
        ->and($last->content())->toBe('Hello')
        ->and($events->dataFor('agent.done'))->toBe([])
        ->and(array_slice($events->names(), -3))->toBe(['agent.step_finish', 'agent.error', 'agent.finish'])
        ->and($events->dataFor('agent.error'))->toBe(['Task cancelled']);
});

test('a cancel on agent.step_finish of a done response returns the text and preview as Cancelled', function () {
    [$agent, $events] = cancellationAgent(
        [[
            new TextDelta('Wrapping up.'),
            new ToolInputStart(0, 'd1', 'done'),
            new ToolInputDelta(0, 'd1', '{"response":"The answer is 4."}'),
            new ToolInputEnd(0, 'd1', 'done', ['response' => 'The answer is 4.']),
            new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
        ]],
        fn(string $event): bool => $event === 'agent.step_finish',
    );

    $output = $agent->run(new UserMessage('2+2?'));
    $messages = $output->conversation?->messages() ?? [];
    $last = end($messages);

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe("Wrapping up.\n\nThe answer is 4.")
        ->and($last)->toBeInstanceOf(AssistantMessage::class)
        ->and($last->content())->toBe("Wrapping up.\n\nThe answer is 4.")
        ->and($last->toolCalls())->toBe([])
        ->and($output->toolResults)->toBe([])
        ->and($events->dataFor('agent.done'))->toBe([])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'd1', 'done', 'cancelled')])
        ->and(array_slice($events->names(), -4))->toBe(['agent.step_finish', 'agent.tool_input_discarded', 'agent.error', 'agent.finish']);
});

test('a cancel from the execution policy while it allows done returns Cancelled, not Done', function () {
    $token = new CancellationTestToken();
    $policy = new class ($token) implements \CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface {
        public function __construct(private CancellationTestToken $token) {}

        public function shouldExecute(string $toolName, array $arguments): true|string
        {
            $this->token->cancelled = true;

            return true;
        }
    };
    $agent = new CancellationTestAgent(
        provider: new ScriptedEventsProvider([[
            new ToolInputStart(0, 'd1', 'done'),
            new ToolInputDelta(0, 'd1', '{"response":"The answer is 4."}'),
            new ToolInputEnd(0, 'd1', 'done', ['response' => 'The answer is 4.']),
            new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
        ]]),
        cancellationToken: $token,
        executionPolicy: $policy,
    );
    $events = new CancellationTestRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('2+2?'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('The answer is 4.')
        ->and($events->dataFor('agent.done'))->toBe([])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'd1', 'done', 'cancelled')])
        ->and(array_slice($events->names(), -3))->toBe(['agent.tool_input_discarded', 'agent.error', 'agent.finish']);
});

test('a stream that ends without a finish once the token is set ends the run as Cancelled', function () {
    [$agent, $events] = cancellationAgent(
        [[new TextDelta('Hel'), new ToolInputStart(0, 'c1', 'echo'), new ToolInputDelta(0, 'c1', '{"te')]],
        fn(string $event): bool => $event === 'agent.tool_input_delta',
    );

    $output = $agent->run(new UserMessage('Say hello.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Hel')
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'cancelled')])
        ->and($events->dataFor('agent.error'))->toBe(['Task cancelled']);
});

test('a cancel on agent.budget_warning before a text answer is accepted returns Cancelled', function () {
    [$agent, $events] = cancellationAgent(
        [[new TextDelta('Hello'), new Finish(ProviderFinishReason::Stop, 'stop', new Usage(promptTokens: 850, completionTokens: 50, totalTokens: 900), 'test-model')]],
        fn(string $event): bool => $event === 'agent.budget_warning',
        ['contextWindow' => new ContextWindow(maxTok: 1_000, reservedTok: 0), 'budgetExitThreshold' => 0.8],
    );

    $output = $agent->run(new UserMessage('Say hello.'));
    $messages = $output->conversation?->messages() ?? [];

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Hello')
        ->and(end($messages)->content())->toBe('Hello')
        ->and($events->dataFor('agent.done'))->toBe([])
        ->and(array_slice($events->names(), -4))->toBe(['agent.step_finish', 'agent.budget_warning', 'agent.error', 'agent.finish']);
});
