<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const ANTHROPIC_REPORTED_USAGE = [
    'input_tokens' => 10,
    'output_tokens' => 5,
    'cache_read_input_tokens' => 3,
    'cache_creation_input_tokens' => 2,
];

function anthropicUsageListener(): UsageListener
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

function anthropicUsageResponse(?string $streamEnd = null): MockResponse
{
    $message = [
        'id' => 'msg_1',
        'model' => 'vendor-model',
        'usage' => ANTHROPIC_REPORTED_USAGE,
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => '{"answer":"yes"}']],
    ];
    $body = json_encode($message, JSON_THROW_ON_ERROR);

    if ($streamEnd !== null) {
        $events = [
            ['type' => 'message_start', 'message' => $message],
            ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'yes']],
            ...($streamEnd === 'error'
                ? [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]
                : [['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']], ['type' => 'message_stop']]),
        ];
        $body = implode('', array_map(static fn (array $event): string => 'data: ' . json_encode($event) . "\n\n", $events));
    }

    return new MockResponse($body, ['response_headers' => ['request-id: req_1']]);
}

test('Anthropic chat report carries ids and cache usage', function () {
    $listener = anthropicUsageListener();
    $provider = (new AnthropicProvider(httpClient: new MockHttpClient(anthropicUsageResponse())))
        ->withUsageListener($listener);

    $response = $provider->chat([new UserMessage('Answer the question')]);

    expect($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expect($report->operation)->toBe('chat')
        ->and($report->requestId)->toBe('req_1')
        ->and($report->responseId)->toBe('msg_1')
        ->and($report->usage)->toEqual($response->usage)
        ->and($report->usage?->cacheReadTokens)->toBe(3)
        ->and($report->usage?->cacheWriteTokens)->toBe(2)
        ->and($report->failed)->toBeFalse();
});

test('Anthropic structured native and both fallbacks report once as structured', function (string $schema, bool $native, bool $decoded) {
    $listener = anthropicUsageListener();
    $response = anthropicUsageResponse();
    $client = new MockHttpClient($response);
    $provider = (new AnthropicProvider(httpClient: $client))->withUsageListener($listener);

    $result = $provider->structured([new UserMessage('Answer the question')], $schema);
    if ($decoded) {
        expect($result)->toBe(['answer' => 'yes']);
    } else {
        expect($result)->toBeInstanceOf(Response::class);
    }

    $body = json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
    expect(isset($body['output_config']['format']))->toBe($native)
        ->and($body)->not->toHaveKey(UsageReport::OPERATION_OPTION)
        ->and($client->getRequestsCount())->toBe(1)
        ->and($listener->reports)->toHaveCount(1);

    $report = $listener->reports[0];
    expect($report->operation)->toBe('structured')
        ->and($report->requestId)->toBe('req_1')
        ->and($report->responseId)->toBe('msg_1')
        ->and($report->usage)->toEqual(new Usage(15, 5, 20, 3, 2))
        ->and($report->failed)->toBeFalse();
})->with([
    'native' => ['{"type":"object","properties":{"answer":{"type":"string"}}}', true, true],
    'unrepresentable schema' => ['{"type":"object"}', false, true],
    'invalid schema' => ['invalid JSON', false, false],
]);

test('Anthropic stream reports by the time it yields Finish', function () {
    $listener = anthropicUsageListener();
    $provider = (new AnthropicProvider(httpClient: new MockHttpClient(anthropicUsageResponse('stop'))))
        ->withUsageListener($listener);

    $events = $provider->streamEvents([new UserMessage('Answer the question')]);
    foreach ($events as $event) {
        if ($event instanceof Finish) {
            break;
        }
    }

    expect($event)->toBeInstanceOf(Finish::class)
        ->and($listener->reports)->toHaveCount(1)
        ->and($listener->reports[0]->usage)->toEqual($event->usage)
        ->and($listener->reports[0]->failed)->toBeFalse();

    unset($events);
    expect($listener->reports)->toHaveCount(1);
});

test('Anthropic stream failure retains the ids and usage received before the error', function () {
    $listener = anthropicUsageListener();
    $provider = (new AnthropicProvider(model: 'requested-model', httpClient: new MockHttpClient(anthropicUsageResponse('error'))))
        ->withUsageListener($listener);

    expect(fn () => iterator_to_array($provider->streamEvents([new UserMessage('Answer the question')])))
        ->toThrow(ProviderException::class, 'Overloaded');

    expect($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expect($report->requestId)->toBe('req_1')
        ->and($report->responseId)->toBe('msg_1')
        ->and($report->usage)->toEqual(new Usage(15, 5, 20, 3, 2))
        ->and($report->model)->toBe('requested-model')
        ->and($report->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($report->failed)->toBeTrue();
});
