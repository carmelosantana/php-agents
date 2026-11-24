<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Contract\UsageReporting;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\CompositeUsageListener;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\MockHttpClient;

final class UsageListenerTestProvider extends OpenAICompatibleProvider
{
    public function emit(UsageReport $report): void
    {
        $this->reportUsage($report);
    }

    public function operation(array &$options, string $own): string
    {
        return $this->usageOperation($options, $own);
    }

    public function duration(): int
    {
        return $this->elapsedMs($this->startClock());
    }

    public static function failed(ProviderFinishReason $reason): bool
    {
        return self::finishedFailed($reason);
    }
}

final class UsageListenerTestRecorder implements UsageListener
{
    /** @var list<UsageReport> */
    public array $reports = [];

    public function onUsage(UsageReport $report): void
    {
        $this->reports[] = $report;
    }
}

final class UsageListenerTestThrower implements UsageListener
{
    public function onUsage(UsageReport $report): void
    {
        throw new Error('private listener details');
    }
}

function usageListenerTestReport(): UsageReport
{
    return new UsageReport(
        operation: 'chat',
        provider: 'OpenAICompatibleProvider',
        requestedModel: 'test-model',
        model: 'served-model',
        usage: new Usage(promptTokens: 3, completionTokens: 2, totalTokens: 5),
        requestId: 'request-1',
        responseId: 'response-1',
        finishReason: ProviderFinishReason::Stop,
        durationMs: 4,
        failed: false,
    );
}

test('withUsageListener returns a copy', function () {
    $provider = new UsageListenerTestProvider('test-model', httpClient: new MockHttpClient());
    $listener = new UsageListenerTestRecorder();
    $report = usageListenerTestReport();

    $copy = $provider->withUsageListener($listener);
    $provider->emit($report);
    $copy->emit($report);

    expect($copy)->toBeInstanceOf(UsageReporting::class)->not->toBe($provider)
        ->and($listener->reports)->toBe([$report]);
});

test('withUsageListener replaces the listener on the copy', function () {
    $first = new UsageListenerTestRecorder();
    $second = new UsageListenerTestRecorder();
    $provider = (new UsageListenerTestProvider('test-model', httpClient: new MockHttpClient()))
        ->withUsageListener($first);
    $report = usageListenerTestReport();

    $provider->withUsageListener($second)->emit($report);
    expect($first->reports)->toBeEmpty()
        ->and($second->reports)->toBe([$report]);

    $provider->emit($report);
    expect($first->reports)->toBe([$report]);
});

test('withModel and withRequestLimits keep the listener', function () {
    $listener = new UsageListenerTestRecorder();
    $provider = (new UsageListenerTestProvider('test-model', httpClient: new MockHttpClient()))
        ->withUsageListener($listener);
    $report = usageListenerTestReport();

    $provider->withModel('other-model')->emit($report);
    expect($listener->reports)->toBe([$report]);
    $provider->withRequestLimits(new RequestLimits())->emit($report);
    expect($listener->reports)->toBe([$report, $report]);
});

test('reportUsage swallows a throwing listener and logs only the class', function () {
    $logger = new class extends AbstractLogger {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message, $context];
        }
    };
    $provider = new UsageListenerTestProvider('test-model', httpClient: new MockHttpClient(), logger: $logger);

    $provider->withUsageListener(new UsageListenerTestThrower())->emit(usageListenerTestReport());

    expect($logger->records)->toHaveCount(1);
    [$level, $message, $context] = $logger->records[0];
    expect($level)->toBe(LogLevel::WARNING)
        ->and($message)->not->toContain('private listener details')
        ->and($context)->toBe(['exception_class' => Error::class]);
});

test('reportUsage isolates a failure while logging a listener error', function () {
    $logger = new class extends AbstractLogger {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('logger failed');
        }
    };
    $provider = new UsageListenerTestProvider('test-model', httpClient: new MockHttpClient(), logger: $logger);

    $provider->withUsageListener(new UsageListenerTestThrower())->emit(usageListenerTestReport());
})->throwsNoExceptions();

test('composite calls every listener even when one throws', function () {
    $first = new UsageListenerTestRecorder();
    $last = new UsageListenerTestRecorder();
    $report = usageListenerTestReport();

    (new CompositeUsageListener($first, new UsageListenerTestThrower(), $last))->onUsage($report);

    expect($first->reports)->toBe([$report])->and($last->reports)->toBe([$report]);
});

test('usageOperation strips the reserved key', function () {
    $provider = new UsageListenerTestProvider('test-model', httpClient: new MockHttpClient());
    $options = ['__php_agents_usage_operation' => 'structured', 'temperature' => 0];

    expect(UsageReport::OPERATION_OPTION)->toBe('__php_agents_usage_operation')
        ->and($provider->operation($options, 'chat'))->toBe('structured')
        ->and($options)->toBe(['temperature' => 0])
        ->and($provider->operation($options, 'chat'))->toBe('chat');
});

test('elapsedMs is non-negative', function () {
    $provider = new UsageListenerTestProvider('test-model', httpClient: new MockHttpClient());

    expect($provider->duration())->toBeGreaterThanOrEqual(0);
});

test('finishedFailed flags only the Error finish reason', function () {
    foreach (ProviderFinishReason::cases() as $reason) {
        expect(UsageListenerTestProvider::failed($reason))->toBe($reason === ProviderFinishReason::Error);
    }
});
