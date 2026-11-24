<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Cli\ClaudeCliVendorAdapter;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessChunk;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessResult;
use CarmeloSantana\PHPAgents\Provider\CliProvider;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use Tests\Support\Http\UsageFixtures;
use Tests\Support\Runtime\FakeCliRuntime;

test('CliProviderUsageReport reports once per run with null vendor IDs', function () {
    $fixture = UsageFixtures::for(CliProvider::class);
    $response = $fixture->chat();

    expect($fixture->inferenceRequests)->toHaveCount(1)
        ->and($fixture->reports)->toHaveCount(1)
        ->and($fixture->reports[0]->operation)->toBe('chat')
        ->and($fixture->reports[0]->requestId)->toBeNull()
        ->and($fixture->reports[0]->responseId)->toBeNull()
        ->and($fixture->reports[0]->usage)->toEqual($response->usage);
});

test('CliProviderUsageReport labels a structured run once', function () {
    $fixture = UsageFixtures::for(CliProvider::class);
    $result = $fixture->structuredPaths()['plain JSON']();

    expect($result)->toBe(['answer' => UsageFixtures::MARKER])
        ->and($fixture->inferenceRequests)->toHaveCount(1)
        ->and($fixture->reports)->toHaveCount(1)
        ->and($fixture->reports[0]->operation)->toBe('structured');
});

test('CliProviderUsageReport reports a nonzero exit with parsed output as failed', function () {
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(
        1,
        '{"result":"Partial answer","model":"resolved-model","usage":{"input_tokens":12,"output_tokens":5}}',
    ));
    $listener = cliUsageReportCollector();
    $provider = (new CliProvider('requested-model', new ClaudeCliVendorAdapter(), $runtime))
        ->withUsageListener($listener);

    $response = $provider->chat([]);

    expect($response->content)->toBe('Partial answer')
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($listener->reports)->toHaveCount(1)
        ->and($listener->reports[0]->failed)->toBeTrue()
        ->and($listener->reports[0]->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($listener->reports[0]->model)->toBe('requested-model')
        ->and($listener->reports[0]->usage)->toEqual($response->usage);
});

test('CliProviderUsageReport logs only the class of a throwing listener', function () {
    $fixture = UsageFixtures::for(CliProvider::class);
    $provider = $fixture->withThrowingListener();

    expect($fixture->chat($provider))->toEqual($fixture->chat($fixture->original))
        ->and($fixture->warnings)->toBe([
            ['Usage listener failed.', ['exception_class' => LogicException::class]],
        ]);
});

test('CliProviderUsageReport preserves constructor calls without a logger', function () {
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(0, '{"result":"Ready"}'));
    $provider = new CliProvider('requested-model', new ClaudeCliVendorAdapter(), $runtime);

    expect($provider->chat([])->content)->toBe('Ready');
});

test('CliProviderUsageReport keeps usage parsed before the consumer abandons a stream', function () {
    $runtime = (new FakeCliRuntime())->withChunks([
        new CliProcessChunk(content: '{"type":"assistant","message":{"content":[{"type":"text","text":"Partial"}],'
            . '"model":"resolved-model","usage":{"input_tokens":12,"output_tokens":5}}}' . "\n"),
        new CliProcessChunk(isLast: true),
    ]);
    $listener = cliUsageReportCollector();
    $provider = (new CliProvider('requested-model', new ClaudeCliVendorAdapter(), $runtime))
        ->withUsageListener($listener);

    $events = $provider->streamEvents([]);
    expect($events->current())->toEqual(new TextDelta('Partial'))
        ->and($listener->reports)->toBeEmpty();
    unset($events);

    expect($listener->reports)->toHaveCount(1)
        ->and($listener->reports[0]->operation)->toBe('stream')
        ->and($listener->reports[0]->failed)->toBeTrue()
        ->and($listener->reports[0]->model)->toBe('requested-model')
        ->and($listener->reports[0]->usage)->toEqual(new Usage(12, 5, 17));
});

test('CliProviderUsageReport reports a stream by the time it yields Finish', function (array $chunks, bool $failed) {
    $runtime = (new FakeCliRuntime())->withChunks($chunks);
    $listener = cliUsageReportCollector();
    $provider = (new CliProvider('requested-model', new ClaudeCliVendorAdapter(), $runtime))
        ->withUsageListener($listener);

    $events = $provider->streamEvents([]);
    expect($events->current())->toBeInstanceOf(Finish::class)
        ->and($listener->reports)->toHaveCount(1)
        ->and($listener->reports[0]->failed)->toBe($failed)
        ->and($listener->reports[0]->finishReason)->toBe($failed ? ProviderFinishReason::Error : ProviderFinishReason::Stop);
    unset($events);

    expect($listener->reports)->toHaveCount(1);
})->with([
    'normal exit' => [[new CliProcessChunk(isLast: true)], false],
    'nonzero exit without an error string' => [[new CliProcessChunk(isLast: true, exitCode: 1)], true],
    'missing final chunk' => [[], true],
]);

function cliUsageReportCollector(): UsageListener
{
    return new class implements UsageListener {
        /** @var list<UsageReport> */
        public array $reports = [];

        public function onUsage(UsageReport $report): void
        {
            $this->reports[] = $report;
        }
    };
}
