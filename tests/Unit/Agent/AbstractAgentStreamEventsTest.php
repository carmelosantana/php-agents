<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Event\RunFinish;
use CarmeloSantana\PHPAgents\Agent\Event\StepFinish;
use CarmeloSantana\PHPAgents\Agent\Event\ToolInputDiscarded;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Agent\RunLimits;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\TerminationException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
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
                usleep(20_000);

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
        $added = ['agent.tool_input_start', 'agent.tool_input_delta', 'agent.tool_input_end', 'agent.tool_input_discarded', 'agent.step_finish', 'agent.finish'];

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
            ['runLimits' => new RunLimits(maxRunSeconds: 0.01)],
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
