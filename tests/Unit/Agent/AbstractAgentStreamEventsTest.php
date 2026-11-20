<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Event\DonePreview;
use CarmeloSantana\PHPAgents\Agent\Event\RunFinish;
use CarmeloSantana\PHPAgents\Agent\Event\StepFinish;
use CarmeloSantana\PHPAgents\Agent\Event\ToolInputDiscarded;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\TerminationException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Tests\Support\Agent\ScriptedEventsProvider;
use Tests\Support\Agent\ScriptedProvider;

final class StreamEventsTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a stream events test agent.';
    }

    public function tools(): array
    {
        return [
            new Tool('echo', 'Echo text.', [new StringParameter('text', 'Text to echo')], fn(array $input): ToolResult => ToolResult::success('echo: ' . ($input['text'] ?? ''))),
            new Tool('slow', 'Sleep briefly.', [], function (array $input): ToolResult {
                usleep(100_000);

                return ToolResult::success('slept');
            }),
            new Tool('halt', 'Stop the loop.', [], fn(array $input): ToolResult => ToolResult::success('not reached')),
        ];
    }
}

/**
 * Runs tools inline, except `halt`, which ends the loop the way a restart tool does.
 */
final class StreamEventsHaltingExecutor implements ToolExecutorInterface
{
    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        return $tool->name() === 'halt' ? throw new TerminationException('Halted.') : $tool->execute($arguments);
    }
}

final class StreamEventsRecorder implements SplObserver
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

    /**
     * The events 0.17 observers know: every event except the 0.18 additions.
     *
     * @return list<array{string, mixed}>
     */
    public function legacy(): array
    {
        $added = ['agent.tool_input_start', 'agent.tool_input_delta', 'agent.tool_input_end', 'agent.tool_input_discarded', 'agent.done_delta', 'agent.step_finish', 'agent.finish'];

        return array_values(array_filter($this->events, fn(array $e): bool => !in_array($e[0], $added, true)));
    }
}

/**
 * One complete tool call: start, its argument JSON in two fragments, end.
 *
 * @param array<string, mixed> $arguments
 * @return list<StreamEvent>
 */
function streamEventsCall(int $index, string $id, string $name, array $arguments): array
{
    $json = (string) json_encode((object) $arguments);
    $cut = intdiv(strlen($json), 2);

    return [
        new ToolInputStart($index, $id, $name),
        new ToolInputDelta($index, $id, substr($json, 0, $cut)),
        new ToolInputDelta($index, $id, substr($json, $cut)),
        new ToolInputEnd($index, $id, $name, $arguments),
    ];
}

/**
 * @param list<array{string, string, array<string, mixed>}> $calls id, name, arguments
 * @return list<StreamEvent>
 */
function streamEventsToolRound(array $calls, ?Usage $usage = null): array
{
    $events = [];
    foreach ($calls as $index => [$id, $name, $arguments]) {
        array_push($events, ...streamEventsCall($index, $id, $name, $arguments));
    }
    $events[] = new Finish(ProviderFinishReason::ToolUse, 'tool_use', $usage, 'test-model');

    return $events;
}

/** @return list<StreamEvent> */
function streamEventsText(string $text, ProviderFinishReason $reason = ProviderFinishReason::Stop, ?Usage $usage = null): array
{
    $events = $text === '' ? [] : [new TextDelta($text)];
    $events[] = new Finish($reason, $reason->value, $usage, 'test-model');

    return $events;
}

/**
 * @param list<list<StreamEvent>> $scripts
 * @param array<string, mixed> $arguments Named AbstractAgent constructor arguments
 * @return array{StreamEventsTestAgent, StreamEventsRecorder}
 */
function streamEventsAgent(array $scripts, array $arguments = []): array
{
    $agent = new StreamEventsTestAgent(...['provider' => new ScriptedEventsProvider($scripts), ...$arguments]);
    $recorder = new StreamEventsRecorder();
    $agent->attach($recorder);

    return [$agent, $recorder];
}

test('a tool round streams tool input, then step_finish, then the tool call and result', function () {
    $usage = new Usage(10, 5, 15);
    [$agent, $events] = streamEventsAgent([
        streamEventsToolRound([['c1', 'echo', ['text' => 'hi']]], $usage),
        streamEventsText('Done.'),
    ]);

    $agent->run(new UserMessage('Echo hi.'));

    expect($events->names())->toBe([
        'agent.start',
        'agent.iteration',
        'agent.tool_input_start',
        'agent.tool_input_delta',
        'agent.tool_input_delta',
        'agent.tool_input_end',
        'agent.step_finish',
        'agent.tool_call',
        'agent.tool_result',
        'agent.iteration',
        'agent.text_delta',
        'agent.step_finish',
        'agent.done',
        'agent.finish',
    ])
        ->and($events->dataFor('agent.tool_input_start'))->toEqual([new ToolInputStart(0, 'c1', 'echo')])
        ->and($events->dataFor('agent.tool_input_delta'))->toEqual([new ToolInputDelta(0, 'c1', '{"text'), new ToolInputDelta(0, 'c1', '":"hi"}')])
        ->and($events->dataFor('agent.tool_input_end'))->toEqual([new ToolInputEnd(0, 'c1', 'echo', ['text' => 'hi'])])
        ->and($events->dataFor('agent.step_finish'))->toEqual([
            new StepFinish(1, ProviderFinishReason::ToolUse, 'tool_use', $usage, 'test-model'),
            new StepFinish(2, ProviderFinishReason::Stop, 'stop', null, 'test-model'),
        ]);
});

test('a plain text run keeps the 0.17 agent events', function () {
    $input = new UserMessage('Say hello.');
    [$agent, $events] = streamEventsAgent([[
        new ReasoningDelta('Thinking'),
        new TextDelta('Hello'),
        new TextDelta(' world'),
        new Finish(ProviderFinishReason::Stop, 'stop', null, 'test-model'),
    ]]);

    $agent->run($input);

    expect($events->legacy())->toEqual([
        ['agent.start', $input],
        ['agent.iteration', 1],
        ['agent.reasoning', 'Thinking'],
        ['agent.text_delta', 'Hello'],
        ['agent.text_delta', ' world'],
        ['agent.done', ['response' => 'Hello world']],
    ]);
});

test('a tool run keeps the 0.17 agent events', function () {
    $input = new UserMessage('Echo hi.');
    [$agent, $events] = streamEventsAgent([
        streamEventsToolRound([['c1', 'echo', ['text' => 'hi']]]),
        streamEventsText('Done.'),
    ]);

    $agent->run($input);

    expect($events->legacy())->toEqual([
        ['agent.start', $input],
        ['agent.iteration', 1],
        ['agent.tool_call', new ToolCall('c1', 'echo', ['text' => 'hi'])],
        ['agent.tool_result', ToolResult::success('echo: hi')->withCallId('c1')],
        ['agent.iteration', 2],
        ['agent.text_delta', 'Done.'],
        ['agent.done', ['response' => 'Done.']],
    ]);
});

test('Output usage sums every step, cache and reasoning fields included', function () {
    [$agent, $events] = streamEventsAgent([
        streamEventsToolRound([['c1', 'echo', ['text' => 'hi']]], new Usage(10, 5, 15, cacheReadTokens: 4, reasoningTokens: 2)),
        streamEventsText('Done.', usage: new Usage(20, 7, 27, reasoningTokens: 3)),
    ]);

    $output = $agent->run(new UserMessage('Echo hi.'));

    $expected = new Usage(30, 12, 42, cacheReadTokens: 4, cacheWriteTokens: null, reasoningTokens: 5);
    expect($output->usage)->toEqual($expected)
        ->and($events->dataFor('agent.finish'))->toEqual([new RunFinish(AgentFinishReason::Stop, $expected, 2, 'test-model')]);
});

test('a call that ended before a MaxTokens finish is discarded, not run', function () {
    [$agent, $events] = streamEventsAgent([[
        new TextDelta('Partial answer'),
        ...streamEventsCall(0, 'c1', 'echo', ['text' => 'hi']),
        new Finish(ProviderFinishReason::MaxTokens, 'max_tokens', null, 'test-model'),
    ]]);

    $output = $agent->run(new UserMessage('Echo hi.'));

    expect($output->content)->toBe('Partial answer')
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'not_tool_use')])
        ->and($events->dataFor('agent.tool_call'))->toBe([])
        ->and(array_slice($events->names(), 6, 3))->toBe(['agent.tool_input_end', 'agent.tool_input_discarded', 'agent.step_finish']);
});

test('a started call with no end before an Error finish is discarded as invalid arguments', function () {
    [$agent, $events] = streamEventsAgent([[
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputDelta(0, 'c1', '{"text": tru'),
        new Finish(ProviderFinishReason::Error, 'error', null, 'test-model'),
    ]]);

    $output = $agent->run(new UserMessage('Echo hi.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'invalid_arguments')])
        ->and($events->dataFor('agent.tool_call'))->toBe([]);
});

test('a call truncated by a MaxTokens finish is discarded as not tool use', function () {
    [$agent, $events] = streamEventsAgent([[
        new TextDelta('Partial answer'),
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputDelta(0, 'c1', '{"text": "h'),
        new Finish(ProviderFinishReason::MaxTokens, 'max_tokens', null, 'test-model'),
    ]]);

    $agent->run(new UserMessage('Echo hi.'));

    expect($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'not_tool_use')]);
});

test('a run limit mid-stream discards every started call before agent.error', function () {
    [$agent, $events] = streamEventsAgent([[
        ...streamEventsCall(0, 'c1', 'echo', ['text' => 'hi']),
        new ToolInputStart(1, 'c2', 'echo'),
        new ToolInputDelta(1, 'c2', '{"text": "' . str_repeat('x', 200)),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ]], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    $output = $agent->run(new UserMessage('Echo hi.'));

    expect($output->content)->toBe('Response size limit reached.')
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([
            new ToolInputDiscarded(0, 'c1', 'echo', 'run_limit'),
            new ToolInputDiscarded(1, 'c2', 'echo', 'run_limit'),
        ])
        ->and($events->dataFor('agent.tool_call'))->toBe([])
        ->and(array_slice($events->names(), -4))->toBe(['agent.tool_input_discarded', 'agent.tool_input_discarded', 'agent.error', 'agent.finish']);
});

test('a token limit after a ToolUse response discards its calls before agent.error', function () {
    [$agent, $events] = streamEventsAgent(
        [streamEventsToolRound([['c1', 'echo', ['text' => 'hi']]], new Usage(10, 1_000_000, 1_000_010))],
        ['runLimits' => new RunLimits(maxEstimatedTokens: 100_000)],
    );

    $output = $agent->run(new UserMessage('Echo hi.'));

    expect($output->content)->toBe('Token limit reached.')
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'run_limit')])
        ->and($events->dataFor('agent.tool_call'))->toBe([])
        ->and(array_slice($events->names(), -4))->toBe(['agent.step_finish', 'agent.tool_input_discarded', 'agent.error', 'agent.finish']);
});

test('a provider exception after a started call discards it before agent.error', function () {
    [$agent, $events] = streamEventsAgent([[
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputDelta(0, 'c1', '{"text": "h'),
        new RuntimeException('Idle timeout reached.'),
    ]]);

    $output = $agent->run(new UserMessage('Echo hi.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'provider_error')])
        ->and(array_slice($events->names(), -3))->toBe(['agent.tool_input_discarded', 'agent.error', 'agent.finish']);
});

test('a stream that ends without a Finish after a started call discards it before agent.error', function () {
    [$agent, $events] = streamEventsAgent([[
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputDelta(0, 'c1', '{"text": "h'),
    ]]);

    $output = $agent->run(new UserMessage('Echo hi.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'provider_error')])
        ->and(array_slice($events->names(), -3))->toBe(['agent.tool_input_discarded', 'agent.error', 'agent.finish']);
});

test('a done call that finishes the run discards the other calls in its response', function () {
    [$agent, $events] = streamEventsAgent([
        streamEventsToolRound([['d1', 'done', ['response' => 'All set.']], ['c2', 'echo', ['text' => 'late']]]),
    ]);

    $output = $agent->run(new UserMessage('Finish.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(1, 'c2', 'echo', 'done_finished_run')])
        ->and($events->dataFor('agent.tool_call'))->toBe([])
        ->and(array_slice($events->names(), -4))->toBe(['agent.step_finish', 'agent.tool_input_discarded', 'agent.done', 'agent.finish']);
});

test('a stream that ends without a Finish is a provider error with no step_finish', function () {
    [$agent, $events] = streamEventsAgent([[new TextDelta('Cut off')]]);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($output->content)->toBe('Provider error: the response ended without a valid finish reason.')
        ->and($events->dataFor('agent.step_finish'))->toBe([])
        ->and(array_slice($events->names(), -2))->toBe(['agent.error', 'agent.finish']);
});

test('agent.finish fires exactly once, last, on every exit path', function (\Closure $make, AgentFinishReason $reason, array $tail) {
    /** @var StreamEventsTestAgent $agent */
    /** @var StreamEventsRecorder $events */
    [$agent, $events, $input] = $make();

    $output = $agent->run($input);

    $finishes = $events->dataFor('agent.finish');
    expect($output)->toBeInstanceOf(Output::class)
        ->and($output->finishReason)->toBe($reason)
        ->and($finishes)->toHaveCount(1)
        ->and($finishes[0])->toEqual(new RunFinish($output->finishReason, $output->usage ?? new Usage(), $output->iterations, $output->model))
        ->and(array_slice($events->names(), -count($tail)))->toBe($tail);
})->with([
    'stop' => [
        fn() => [...streamEventsAgent([streamEventsText('Hi.')]), new UserMessage('Hi')],
        AgentFinishReason::Stop,
        ['agent.step_finish', 'agent.done', 'agent.finish'],
    ],
    'reasoning-only fallback' => [
        fn() => [...streamEventsAgent(
            [[new ReasoningDelta('The answer is 4.'), new Finish(ProviderFinishReason::Stop, 'stop', null, 'test-model')]],
            ['emptyResponseHandling' => EmptyResponseHandling::Fallback],
        ), new UserMessage('2+2?')],
        AgentFinishReason::Stop,
        ['agent.step_finish', 'agent.empty_response', 'agent.warning', 'agent.done', 'agent.finish'],
    ],
    'done' => [
        fn() => [...streamEventsAgent([streamEventsToolRound([['d1', 'done', ['response' => 'Fin.']]])]), new UserMessage('Hi')],
        AgentFinishReason::Done,
        ['agent.step_finish', 'agent.done', 'agent.finish'],
    ],
    'max iterations' => [
        fn() => [...streamEventsAgent([streamEventsToolRound([['c1', 'echo', []]])], ['maxIter' => 1]), new UserMessage('Hi')],
        AgentFinishReason::MaxIterations,
        ['agent.tool_result', 'agent.error', 'agent.finish'],
    ],
    'tool-call limit per run' => [
        fn() => [...streamEventsAgent(
            [streamEventsToolRound([['c1', 'echo', []], ['c2', 'echo', []]])],
            ['runLimits' => new RunLimits(maxToolCallsPerRun: 1)],
        ), new UserMessage('Hi')],
        AgentFinishReason::BudgetExhausted,
        ['agent.tool_result', 'agent.warning', 'agent.finish'],
    ],
    'budget wrap-up exhausted' => [
        fn() => [...streamEventsAgent(
            [streamEventsToolRound([['c1', 'echo', []]])],
            ['contextWindow' => new ContextWindow(maxTok: 100_000, reservedTok: 0), 'budgetExitThreshold' => 0.000001, 'budgetExitWrapUpIterations' => 0],
        ), new UserMessage('Hi')],
        AgentFinishReason::BudgetExhausted,
        ['agent.tool_result', 'agent.warning', 'agent.finish'],
    ],
    'run time limit' => [
        fn() => [...streamEventsAgent(
            [streamEventsToolRound([['c1', 'slow', []]])],
            ['runLimits' => new RunLimits(maxRunSeconds: 0.05)],
        ), new UserMessage('Hi')],
        AgentFinishReason::BudgetExhausted,
        ['agent.tool_result', 'agent.error', 'agent.finish'],
    ],
    'response size limit' => [
        fn() => [...streamEventsAgent([streamEventsText('Far too long.')], ['runLimits' => new RunLimits(maxResponseBytes: 5)]), new UserMessage('Hi')],
        AgentFinishReason::BudgetExhausted,
        ['agent.iteration', 'agent.error', 'agent.finish'],
    ],
    'token limit' => [
        fn() => [...streamEventsAgent([streamEventsText('Hi.')], ['runLimits' => new RunLimits(maxEstimatedTokens: 1)]), new UserMessage('Hi')],
        AgentFinishReason::BudgetExhausted,
        ['agent.iteration', 'agent.error', 'agent.finish'],
    ],
    'empty response exhaustion' => [
        fn() => [...streamEventsAgent([streamEventsText('')], ['maxEmptyResponseRetries' => 0]), new UserMessage('Hi')],
        AgentFinishReason::EmptyResponse,
        ['agent.step_finish', 'agent.empty_response', 'agent.error', 'agent.finish'],
    ],
    'refusal' => [
        fn() => [...streamEventsAgent([streamEventsText('No.', ProviderFinishReason::Refusal)]), new UserMessage('Hi')],
        AgentFinishReason::Refusal,
        ['agent.step_finish', 'agent.finish'],
    ],
    'content filter' => [
        fn() => [...streamEventsAgent([streamEventsText('', ProviderFinishReason::ContentFilter)]), new UserMessage('Hi')],
        AgentFinishReason::ContentFilter,
        ['agent.step_finish', 'agent.finish'],
    ],
    'context window' => [
        fn() => [...streamEventsAgent([streamEventsText('', ProviderFinishReason::ContextWindowExceeded)]), new UserMessage('Hi')],
        AgentFinishReason::ContextWindowExceeded,
        ['agent.step_finish', 'agent.finish'],
    ],
    'provider error finish' => [
        fn() => [...streamEventsAgent([streamEventsText('', ProviderFinishReason::Error)]), new UserMessage('Hi')],
        AgentFinishReason::Error,
        ['agent.step_finish', 'agent.error', 'agent.finish'],
    ],
    'provider exception' => [
        fn() => [...streamEventsAgent([]), new UserMessage('Hi')],
        AgentFinishReason::Error,
        ['agent.iteration', 'agent.error', 'agent.finish'],
    ],
    'tool termination' => [
        fn() => [...streamEventsAgent(
            [streamEventsToolRound([['c1', 'halt', []]])],
            ['toolExecutor' => new StreamEventsHaltingExecutor()],
        ), new UserMessage('Hi')],
        AgentFinishReason::Error,
        ['agent.step_finish', 'agent.tool_call', 'agent.tool_result', 'agent.finish'],
    ],
    'input too large' => [
        fn() => [...streamEventsAgent([streamEventsText('Hi.')], ['runLimits' => new RunLimits(maxInputTokens: 1)]), new UserMessage(str_repeat('x', 400))],
        AgentFinishReason::BudgetExhausted,
        ['agent.start', 'agent.error', 'agent.finish'],
    ],
]);

/**
 * Denies every `done` call; allows everything else.
 */
final class StreamEventsDenyDonePolicy implements ToolExecutionPolicyInterface
{
    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        return $toolName === 'done' ? 'not yet' : true;
    }
}

test('a done answer streamed over three deltas fires three done_delta events, then agent.done', function () {
    [$agent, $events] = streamEventsAgent([[
        new ToolInputStart(0, 'd1', 'done'),
        new ToolInputDelta(0, 'd1', '{"response":"Hel'),
        new ToolInputDelta(0, 'd1', 'lo, wor'),
        new ToolInputDelta(0, 'd1', 'ld."}'),
        new ToolInputEnd(0, 'd1', 'done', ['response' => 'Hello, world.']),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ]]);

    $output = $agent->run(new UserMessage('Greet.'));

    $previewNames = array_values(array_filter($events->names(), fn(string $n): bool => in_array($n, ['agent.done_delta', 'agent.done'], true)));
    expect($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($output->content)->toBe('Hello, world.')
        ->and($events->dataFor('agent.done_delta'))->toEqual([
            new DonePreview('d1', 'Hel'),
            new DonePreview('d1', 'lo, wor'),
            new DonePreview('d1', 'ld.'),
        ])
        ->and($previewNames)->toBe(['agent.done_delta', 'agent.done_delta', 'agent.done_delta', 'agent.done'])
        ->and($events->dataFor('agent.tool_input_discarded'))->toBe([]);
});

test('a whole-call provider previews a done answer in one done_delta', function () {
    $agent = new StreamEventsTestAgent(new ScriptedProvider([
        new Response('', ProviderFinishReason::ToolUse, [new ToolCall('d1', 'done', ['response' => 'All set.'])], 'test-model'),
    ]));
    $events = new StreamEventsRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Finish.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($events->dataFor('agent.done_delta'))->toEqual([new DonePreview('d1', 'All set.')]);
});

test('a second done call in a response is not previewed and is discarded as a duplicate', function () {
    [$agent, $events] = streamEventsAgent([
        streamEventsToolRound([['d1', 'done', ['response' => 'First.']], ['d2', 'done', ['response' => 'Second.']]]),
    ]);

    $output = $agent->run(new UserMessage('Finish.'));

    $previewIds = array_map(fn(DonePreview $p): string => $p->callId, $events->dataFor('agent.done_delta'));
    expect($output->content)->toBe('First.')
        ->and(array_unique($previewIds))->toBe(['d1'])
        ->and(implode('', array_map(fn(DonePreview $p): string => $p->delta, $events->dataFor('agent.done_delta'))))->toBe('First.')
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(1, 'd2', 'done', 'duplicate_done')])
        ->and(array_slice($events->names(), -4))->toBe(['agent.step_finish', 'agent.tool_input_discarded', 'agent.done', 'agent.finish']);
});

test('a policy-denied done is discarded as denied, a later done as a duplicate, before the tool round', function () {
    [$agent, $events] = streamEventsAgent(
        [
            streamEventsToolRound([['d1', 'done', ['response' => 'Early.']], ['d2', 'done', ['response' => 'Again.']]]),
            streamEventsText('Carrying on.'),
        ],
        ['executionPolicy' => new StreamEventsDenyDonePolicy()],
    );

    $output = $agent->run(new UserMessage('Finish.'));

    $names = $events->names();
    $stepFinish = array_search('agent.step_finish', $names, true);
    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('Carrying on.')
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([
            new ToolInputDiscarded(0, 'd1', 'done', 'denied'),
            new ToolInputDiscarded(1, 'd2', 'done', 'duplicate_done'),
        ])
        ->and(array_slice($names, $stepFinish, 4))->toBe(['agent.step_finish', 'agent.tool_input_discarded', 'agent.tool_input_discarded', 'agent.tool_call']);
});

test('a done call with invalid arguments is discarded as invalid_done and the run continues', function () {
    [$agent, $events] = streamEventsAgent([
        streamEventsToolRound([['d1', 'done', ['response' => 'Half.', 'extra' => true]]]),
        streamEventsText('Retried.'),
    ]);

    $output = $agent->run(new UserMessage('Finish.'));

    expect($output->content)->toBe('Retried.')
        ->and($events->dataFor('agent.done_delta'))->not->toBe([])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'd1', 'done', 'invalid_done')])
        ->and($events->dataFor('agent.done'))->toBe([['response' => 'Retried.']]);
});

// Stream resource limits: maxToolCallsPerTurn is enforced while the stream is read, and every
// retained event field counts toward maxResponseBytes.

test('calls past maxToolCallsPerTurn are discarded as they open and never retained', function () {
    $calls = array_map(fn(int $n): array => ["c{$n}", 'echo', ['text' => "t{$n}"]], range(1, 4));
    [$agent, $events] = streamEventsAgent(
        [streamEventsToolRound($calls), streamEventsText('Done.')],
        ['runLimits' => new RunLimits(maxToolCallsPerTurn: 2)],
    );

    $output = $agent->run(new UserMessage('Echo.'));
    $assistant = array_values(array_filter($output->conversation->messages(), fn($m) => $m->toolCalls() !== []));

    expect($events->dataFor('agent.tool_input_discarded'))->toEqual([
        new ToolInputDiscarded(2, 'c3', 'echo', 'tool_call_limit'),
        new ToolInputDiscarded(3, 'c4', 'echo', 'tool_call_limit'),
    ])
        ->and(array_map(fn(ToolInputStart $s): string => $s->id, $events->dataFor('agent.tool_input_start')))->toBe(['c1', 'c2', 'c3', 'c4'])
        ->and(array_map(fn(ToolInputDelta $d): string => $d->id, $events->dataFor('agent.tool_input_delta')))->toBe(['c1', 'c1', 'c2', 'c2'])
        ->and(array_map(fn(ToolInputEnd $e): string => $e->id, $events->dataFor('agent.tool_input_end')))->toBe(['c1', 'c2'])
        ->and(array_map(fn(ToolCall $c): string => $c->id, $events->dataFor('agent.tool_call')))->toBe(['c1', 'c2'])
        ->and(array_map(fn(ToolResult $r): ?string => $r->callId, $output->toolResults))->toBe(['c1', 'c2'])
        ->and($assistant)->toHaveCount(1)
        ->and(array_map(fn(ToolCall $c): string => $c->id, $assistant[0]->toolCalls()))->toBe(['c1', 'c2'])
        ->and($events->dataFor('agent.warning'))->toBe(['Tool call limit reached: at most 2 calls per turn.'])
        ->and($output->finishReason)->toBe(AgentFinishReason::Stop);

    // Each over-cap call is discarded right after it opens.
    expect(array_slice($events->names(), 2, 10))->toBe([
        'agent.tool_input_start', 'agent.tool_input_delta', 'agent.tool_input_delta', 'agent.tool_input_end',
        'agent.tool_input_start', 'agent.tool_input_delta', 'agent.tool_input_delta', 'agent.tool_input_end',
        'agent.tool_input_start', 'agent.tool_input_discarded',
    ]);
});

test('the first done call of a response is outside the per-turn cap, a later one is not', function () {
    [$agent, $events] = streamEventsAgent([
        [
            ...streamEventsCall(0, 'c1', 'echo', ['text' => 'hi']),
            ...streamEventsCall(1, 'd1', 'done', []),
            ...streamEventsCall(2, 'd2', 'done', ['response' => 'Again.']),
            new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
        ],
        streamEventsText('Done.'),
    ], ['runLimits' => new RunLimits(maxToolCallsPerTurn: 1)]);

    $output = $agent->run(new UserMessage('Echo hi.'));

    expect($events->dataFor('agent.tool_input_discarded'))->toEqual([
        new ToolInputDiscarded(2, 'd2', 'done', 'tool_call_limit'),
        new ToolInputDiscarded(1, 'd1', 'done', 'invalid_done'),
    ])
        ->and(array_map(fn(ToolResult $r): ?string => $r->callId, $output->toolResults))->toBe(['c1', 'd1'])
        ->and($output->content)->toBe('Done.');
});

test('maxResponseBytes counts ToolInputEnd arguments a provider sent without deltas', function () {
    [$agent, $events] = streamEventsAgent([[
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputEnd(0, 'c1', 'echo', ['text' => str_repeat('x', 200)]),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    $output = $agent->run(new UserMessage('Echo.'));

    expect($output->content)->toBe('Response size limit reached.')
        ->and($events->dataFor('agent.tool_call'))->toBe([])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(0, 'c1', 'echo', 'run_limit')]);
});

test('maxResponseBytes counts ToolInputEnd metadata', function () {
    [$agent] = streamEventsAgent([[
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputEnd(0, 'c1', 'echo', [], ['thoughtSignature' => str_repeat('s', 200)]),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    expect($agent->run(new UserMessage('Echo.'))->content)->toBe('Response size limit reached.');
});

test('maxResponseBytes counts tool call ids and names', function (string $id, string $name) {
    [$agent] = streamEventsAgent([[
        new ToolInputStart(0, $id, $name),
        new ToolInputEnd(0, $id, $name, []),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    expect($agent->run(new UserMessage('Echo.'))->content)->toBe('Response size limit reached.');
})->with([
    'id' => [str_repeat('i', 200), 'echo'],
    'name' => ['c1', str_repeat('n', 200)],
]);

test('maxResponseBytes counts an end whose start never came', function () {
    [$agent] = streamEventsAgent([[
        new ToolInputEnd(0, str_repeat('i', 200), 'echo', []),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    expect($agent->run(new UserMessage('Echo.'))->content)->toBe('Response size limit reached.');
});

test('maxResponseBytes counts the finish raw reason and model', function (Finish $finish) {
    [$agent] = streamEventsAgent([[new TextDelta('Hi.'), $finish]], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    expect($agent->run(new UserMessage('Hi'))->content)->toBe('Response size limit reached.');
})->with(fn() => [
    'raw reason' => [new Finish(ProviderFinishReason::Stop, str_repeat('r', 200), null, 'test-model')],
    'model' => [new Finish(ProviderFinishReason::Stop, 'stop', null, str_repeat('m', 200))],
]);

test('streamed argument bytes are counted once, with ids, names and finish metadata', function () {
    // Round 1: deltas {"text":"hi"} 13 + id c1 2 + name echo 4 + tool_use 8 + test-model 10 = 37.
    // Round 2: Done. 5 + stop 4 + test-model 10 = 19. The run receives 56 bytes.
    $run = function (int $cap): Output {
        [$agent] = streamEventsAgent(
            [streamEventsToolRound([['c1', 'echo', ['text' => 'hi']]]), streamEventsText('Done.')],
            ['runLimits' => new RunLimits(maxResponseBytes: $cap)],
        );

        return $agent->run(new UserMessage('Echo hi.'));
    };

    expect($run(56)->content)->toBe('Done.')
        ->and($run(55)->content)->toBe('Response size limit reached.');
});

test('maxResponseBytes counts the id of an end whose deltas came without a start', function () {
    [$agent] = streamEventsAgent([[
        new ToolInputDelta(0, 'c1', '{}'),
        new ToolInputEnd(0, str_repeat('i', 200), 'echo', []),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    expect($agent->run(new UserMessage('Echo.'))->content)->toBe('Response size limit reached.');
});

test('an end that arrives without a start is admitted under the per-turn cap', function () {
    [$agent, $events] = streamEventsAgent([[
        new ToolInputEnd(0, 'c1', 'echo', ['text' => 'a']),
        new ToolInputEnd(1, 'c2', 'echo', ['text' => 'b']),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxToolCallsPerTurn: 1)]);

    $output = $agent->run(new UserMessage('Echo.'));

    expect(array_map(fn(ToolResult $r): ?string => $r->callId, $output->toolResults))->toBe(['c1'])
        ->and($events->dataFor('agent.tool_input_discarded'))->toEqual([new ToolInputDiscarded(1, 'c2', 'echo', 'tool_call_limit')]);
});

test('finish telemetry scrubs a hostile raw reason and model: secrets, control and bidi characters, length', function () {
    $key = 'sk-' . str_repeat('A1b2', 8);
    $rawReason = "stop\e[31m {$key}\u{202E}gnp.exe\u{009B}2J\u{2066}x\u{2069}\x07";
    $model = "gpt\u{202D}-4o\e]0;title\x07" . str_repeat('m', 1000);
    [$agent, $events] = streamEventsAgent([[
        new TextDelta('Hello'),
        new Finish(ProviderFinishReason::Stop, $rawReason, null, $model),
    ]]);

    $output = $agent->run(new UserMessage('Say hello.'));

    /** @var StepFinish $step */
    $step = $events->dataFor('agent.step_finish')[0];
    /** @var RunFinish $finish */
    $finish = $events->dataFor('agent.finish')[0];

    expect($step->rawReason)->toBe('stop [31m [redacted] gnp.exe 2J x')
        ->and($step->reason)->toBe(ProviderFinishReason::Stop);

    foreach ([$step->rawReason, $step->model, $finish->model] as $text) {
        expect($text)->not->toContain($key)
            ->and(preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text))->toBe(0)
            ->and(mb_strlen($text, 'UTF-8'))->toBeLessThanOrEqual(129);
    }

    expect($step->model)->toStartWith('gpt -4o ]0;title mmm')->toEndWith('…')
        ->and($finish->model)->toBe($step->model)
        ->and($output->model)->toBe($model);
});

// Fix round 1: an end that disagrees with its start.

test('maxResponseBytes counts an end\'s id and name when they differ from its start\'s', function () {
    [$agent] = streamEventsAgent([[
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputDelta(0, 'c1', '{}'),
        new ToolInputEnd(0, str_repeat('i', 500), str_repeat('n', 500), []),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxResponseBytes: 100)]);

    expect($agent->run(new UserMessage('Echo.'))->content)->toBe('Response size limit reached.');
});

test('the kept call takes its id and name from its start, not from a disagreeing end', function () {
    [$agent, $events] = streamEventsAgent([[
        new ToolInputStart(0, 'c1', 'echo'),
        new ToolInputDelta(0, 'c1', '{"text":"hi"}'),
        new ToolInputEnd(0, 'other', 'halt', ['text' => 'hi']),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')]);

    $output = $agent->run(new UserMessage('Echo.'));

    expect($events->dataFor('agent.tool_input_end'))->toEqual([new ToolInputEnd(0, 'c1', 'echo', ['text' => 'hi'])])
        ->and(array_map(fn(ToolCall $c): string => $c->id . ':' . $c->name, $events->dataFor('agent.tool_call')))->toBe(['c1:echo'])
        ->and($output->toolResults[0]->content)->toBe('echo: hi');
});

test('a start named done cannot carry an ordinary tool past the per-turn cap', function () {
    [$agent, $events] = streamEventsAgent([[
        ...streamEventsCall(0, 'c1', 'echo', ['text' => 'a']),
        new ToolInputStart(1, 'd1', 'done'),
        new ToolInputDelta(1, 'd1', '{"text":"b"}'),
        new ToolInputEnd(1, 'd1', 'echo', ['text' => 'b']),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model'),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxToolCallsPerTurn: 1)]);

    $output = $agent->run(new UserMessage('Echo.'));

    expect(array_map(fn(ToolCall $c): string => $c->name, $events->dataFor('agent.tool_call')))->toBe(['echo', 'done'])
        ->and($output->toolResults)->toHaveCount(2)
        ->and($output->toolResults[0]->content)->toBe('echo: a')
        ->and($output->toolResults[1]->content)->not->toBe('echo: b');
});

test('content blocks kept in the conversation drop the tool_use blocks of calls past the cap', function () {
    $blocks = [
        ['type' => 'thinking', 'thinking' => 'Two.', 'signature' => 'sig'],
        ['type' => 'tool_use', 'id' => 'c1', 'name' => 'echo', 'input' => ['text' => 'a']],
        ['type' => 'tool_use', 'id' => 'c2', 'name' => 'echo', 'input' => ['text' => str_repeat('b', 1000)]],
    ];
    [$agent] = streamEventsAgent([[
        ...streamEventsCall(0, 'c1', 'echo', ['text' => 'a']),
        ...streamEventsCall(1, 'c2', 'echo', ['text' => str_repeat('b', 1000)]),
        new Finish(ProviderFinishReason::ToolUse, 'tool_use', null, 'test-model', $blocks),
    ], streamEventsText('Done.')], ['runLimits' => new RunLimits(maxToolCallsPerTurn: 1)]);

    $output = $agent->run(new UserMessage('Echo.'));
    $assistant = array_values(array_filter($output->conversation->messages(), fn($m) => $m instanceof \CarmeloSantana\PHPAgents\Message\AssistantMessage && $m->contentBlocks() !== []));

    expect($assistant)->toHaveCount(1)
        ->and($assistant[0]->contentBlocks())->toBe([$blocks[0], $blocks[1]]);
});
