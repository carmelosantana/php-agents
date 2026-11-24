<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\CliProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaCppProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaServerProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Tests\Support\Http\UsageFixture;
use Tests\Support\Http\UsageFixtures;

const USAGE_CONFORMANCE_PROVIDERS = [
    AnthropicProvider::class,
    CliProvider::class,
    GeminiProvider::class,
    LlamaServerProvider::class,
    MistralProvider::class,
    OllamaProvider::class,
    OpenAICompatibleProvider::class,
    OpenAIResponsesProvider::class,
    XAIProvider::class,
];

test('UsageConformance every provider has a fixture', function () {
    $discovered = [];
    foreach (glob(__DIR__ . '/../../../src/Provider/*.php') as $file) {
        $class = 'CarmeloSantana\\PHPAgents\\Provider\\' . basename($file, '.php');
        if ($class === LlamaCppProvider::class || !is_subclass_of($class, ProviderInterface::class)) {
            continue;
        }

        if (!(new ReflectionClass($class))->isAbstract()) {
            $discovered[] = $class;
            expect(UsageFixtures::has($class))->toBeTrue("add a usage fixture for {$class}");
            expect(UsageFixtures::for($class)->original)->toBeInstanceOf($class);
        }
    }

    sort($discovered);
    expect($discovered)->toBe(USAGE_CONFORMANCE_PROVIDERS);
});

dataset('usage conformance providers', array_combine(
    array_map(static fn (string $class): string => (new ReflectionClass($class))->getShortName(), USAGE_CONFORMANCE_PROVIDERS),
    USAGE_CONFORMANCE_PROVIDERS,
));

function usageConformanceReport(UsageFixture $fixture, string $operation, bool $failed = false): UsageReport
{
    expect($fixture->reports)->toHaveCount(1, $operation)
        ->and($fixture->inferenceRequests)->toHaveCount(1, $operation)
        ->and($fixture->inferenceRequests[0])->not->toContain(UsageReport::OPERATION_OPTION);

    $report = $fixture->reports[0];
    expect($report->operation)->toBe($operation)
        ->and($report->provider)->toBe((new ReflectionClass($fixture->original))->getShortName())
        ->and($report->requestedModel)->toBe($fixture->original->getModel())
        ->and($report->durationMs)->toBeGreaterThanOrEqual(0)
        ->and($report->failed)->toBe($failed);

    if ($failed) {
        expect($report->finishReason)->toBe(ProviderFinishReason::Error);
    } else {
        expect($report->model)->toBe($fixture->expectedModel());
    }

    return $report;
}

function assertUsageConformanceIds(UsageFixture $fixture, UsageReport $report): void
{
    expect(['requestId' => $report->requestId, 'responseId' => $report->responseId])
        ->toBe($fixture->expectedIds());
}

function assertUsageConformanceResult(UsageReport $report, Response|Finish $result): void
{
    expect($result->usage)->not->toBeNull()
        ->and($report->usage)->toEqual($result->usage)
        ->and($report->finishReason)->toBe($result instanceof Finish ? $result->reason : $result->finishReason);
}

test('UsageConformance reports chat and full streams once', function (string $class) {
    $fixture = UsageFixtures::for($class);
    $response = $fixture->chat();
    $report = usageConformanceReport($fixture, 'chat');
    assertUsageConformanceIds($fixture, $report);
    assertUsageConformanceResult($report, $response);

    $fixture = UsageFixtures::for($class);
    $finish = $fixture->streamFull();
    $report = usageConformanceReport($fixture, 'stream');
    assertUsageConformanceIds($fixture, $report);
    assertUsageConformanceResult($report, $finish);
})->with('usage conformance providers');

test('UsageConformance reports abandoned and truncated streams once', function (string $class) {
    $fixture = UsageFixtures::for($class);
    $fixture->streamAbandoned();
    $report = usageConformanceReport($fixture, 'stream', failed: true);
    assertUsageConformanceIds($fixture, $report);
    expect($report->usage)->toEqual($fixture->abandonedUsage());

    $fixture = UsageFixtures::for($class);
    $finish = $fixture->truncatedStream();
    expect($finish->reason)->toBe(ProviderFinishReason::Error);
    $report = usageConformanceReport($fixture, 'stream', failed: true);
    assertUsageConformanceIds($fixture, $report);
    expect($report->usage)->toEqual($finish->usage);
})->with('usage conformance providers');

test('UsageConformance labels every structured path once', function (string $class) {
    $fixture = UsageFixtures::for($class);
    $paths = $fixture->structuredPaths();
    expect($paths)->not->toBeEmpty();
    foreach ($paths as $path => $structured) {
        $fixture->reports = $fixture->inferenceRequests = [];
        $result = $structured();
        expect($fixture->reports)->toHaveCount(1, $path);
        $report = usageConformanceReport($fixture, 'structured');
        assertUsageConformanceIds($fixture, $report);
        if ($result instanceof Response) {
            assertUsageConformanceResult($report, $result);
        } else {
            expect($result)->toBeArray()
                ->and($report->usage)->toEqual($fixture->structuredUsage());
        }
    }
})->with('usage conformance providers');

test('UsageConformance reports request failures and preserves exceptions', function (string $class) {
    $fixture = UsageFixtures::for($class);
    foreach (['http500', 'transportError'] as $failure) {
        $fixture->reports = $fixture->inferenceRequests = [];
        $original = $fixture->{$failure}($fixture->original);
        expect($fixture->reports)->toBeEmpty();

        $fixture->inferenceRequests = [];
        $error = $fixture->{$failure}();
        expect($error::class)->toBe($original::class)
            ->and($error->getCode())->toBe($original->getCode())
            ->and($error->getMessage())->toBe($original->getMessage());
        if ($failure === 'transportError') {
            expect($error)->toBe($original);
        }

        $report = usageConformanceReport($fixture, 'chat', failed: true);
        expect($report->usage)->toBeNull()
            ->and($report->model)->toBe($report->requestedModel)
            ->and($report->requestId)->toBe($failure === 'http500' ? $fixture->expectedIds()['requestId'] : null)
            ->and($report->responseId)->toBeNull();
    }
})->with('usage conformance providers');

test('UsageConformance discovery and availability emit no reports', function (string $class) {
    $fixture = UsageFixtures::for($class);
    $fixture->provider()->models();
    expect($fixture->provider()->isAvailable())->toBeTrue()
        ->and($fixture->reports)->toBeEmpty();
})->with('usage conformance providers');

test('UsageConformance listener exceptions preserve results and are redacted in warnings', function (string $class) {
    $fixture = UsageFixtures::for($class);
    $provider = $fixture->withThrowingListener();
    expect($fixture->chat($provider))->toEqual($fixture->chat($fixture->original))
        ->and($fixture->transportError($provider))->toBe($fixture->transportError($fixture->original))
        ->and($fixture->warnings)->toHaveCount(2);

    foreach ($fixture->warnings as [$message, $context]) {
        expect($message)->not->toContain(UsageFixtures::MARKER)
            ->and($context)->toBe(['exception_class' => LogicException::class]);
    }
})->with('usage conformance providers');

test('UsageConformance copies keep the listener and the original stays unlistened', function (string $class) {
    $fixture = UsageFixtures::for($class);
    $modelCopy = $fixture->provider()->withModel('fixture-copy');
    $fixture->chat($modelCopy);
    $fixture->chat($fixture->withRequestLimits());
    $fixture->chat($fixture->original);

    expect($fixture->reports)->toHaveCount(2)
        ->and($fixture->reports[0]->requestedModel)->toBe('fixture-copy')
        ->and($fixture->reports[1]->requestedModel)->toBe($fixture->original->getModel());
})->with('usage conformance providers');

test('UsageConformance reports never contain content', function (string $class) {
    $fixture = UsageFixtures::for($class);
    $fixture->chat();
    $fixture->streamFull();
    $fixture->streamAbandoned();
    $fixture->truncatedStream();
    $fixture->http500();
    $fixture->transportError();

    $paths = $fixture->structuredPaths();
    foreach ($paths as $structured) {
        $structured();
    }

    expect($fixture->reports)->toHaveCount(6 + count($paths));
    foreach ($fixture->reports as $report) {
        expect(serialize($report))->not->toContain(UsageFixtures::MARKER);
    }
})->with('usage conformance providers');
