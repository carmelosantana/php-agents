<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const RESPONSES_USAGE_METADATA = [
    'id' => 'resp_3',
    'model' => 'vendor-model',
    'usage' => [
        'input_tokens' => 12,
        'output_tokens' => 5,
        'input_tokens_details' => ['cached_tokens' => 4],
        'output_tokens_details' => ['reasoning_tokens' => 2],
    ],
];

function responsesUsageListener(): UsageListener
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

function expectResponsesUsageMetadata(UsageReport $report): void
{
    expect($report->requestId)->toBe('req_3')
        ->and($report->responseId)->toBe('resp_3')
        ->and($report->requestedModel)->toBe('requested-model')
        ->and($report->usage)->toEqual(new Usage(12, 5, 17, cacheReadTokens: 4, reasoningTokens: 2));
}

test('OpenAIResponsesUsageReport native structured and its chat fallback report once as structured', function (bool $native) {
    $output = $native
        ? ['type' => 'function_call', 'name' => 'structured_output', 'arguments' => '{"answer":"yes"}']
        : ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"answer":"yes"}']]];
    $response = new MockResponse(json_encode([
        ...RESPONSES_USAGE_METADATA,
        'status' => 'completed',
        'output' => [$output],
    ], JSON_THROW_ON_ERROR), ['response_headers' => ['x-request-id: req_3']]);
    $listener = responsesUsageListener();
    $client = new MockHttpClient($response);
    $provider = (new OpenAIResponsesProvider('requested-model', httpClient: $client))->withUsageListener($listener);

    $result = $provider->structured([new UserMessage('Answer the question')], $native ? '{"type":"object"}' : 'invalid JSON');

    if ($native) {
        expect($result)->toBe(['answer' => 'yes']);
    } else {
        expect($result)->toBeInstanceOf(Response::class)
            ->and($result->content)->toBe('{"answer":"yes"}');
    }
    $body = json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
    expect(isset($body['tool_choice']))->toBe($native)
        ->and($body)->not->toHaveKey(UsageReport::OPERATION_OPTION)
        ->and($client->getRequestsCount())->toBe(1)
        ->and($listener->reports)->toHaveCount(1);

    $report = $listener->reports[0];
    expectResponsesUsageMetadata($report);
    expect($report->operation)->toBe('structured')
        ->and($report->model)->toBe('vendor-model')
        ->and($report->finishReason)->toBe($native ? ProviderFinishReason::ToolUse : ProviderFinishReason::Stop)
        ->and($report->failed)->toBeFalse();
})->with(['native' => [true], 'fallback' => [false]]);

test('OpenAIResponsesUsageReport response.failed event reports failed', function (?array $error) {
    $body = 'data: ' . json_encode([
        'type' => 'response.failed',
        'response' => [...RESPONSES_USAGE_METADATA, 'status' => 'failed', 'error' => $error],
    ], JSON_THROW_ON_ERROR) . "\n\n";
    $response = new MockResponse($body, ['response_headers' => ['x-request-id: req_3']]);
    $listener = responsesUsageListener();
    $client = new MockHttpClient($response);
    $provider = (new OpenAIResponsesProvider('requested-model', httpClient: $client))->withUsageListener($listener);
    $events = $provider->streamEvents([new UserMessage('Answer the question')]);

    if ($error === null) {
        expect($events->current())->toBeInstanceOf(Finish::class)
            ->and($events->current()->reason)->toBe(ProviderFinishReason::Error)
            ->and($listener->reports)->toHaveCount(1);
    } else {
        expect(fn () => $events->current())->toThrow(ProviderException::class, $error['message']);
    }
    unset($events);

    expect($client->getRequestsCount())->toBe(1)
        ->and($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expectResponsesUsageMetadata($report);
    expect($report->operation)->toBe('stream')
        ->and($report->model)->toBe('requested-model')
        ->and($report->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($report->failed)->toBeTrue();
})->with([
    'error finish' => [null],
    'exception' => [['code' => 'server_error', 'message' => 'The server had an error.']],
]);

test('OpenAIResponsesUsageReport abandoned stream retains usage received before Finish', function () {
    $body = '';
    foreach ([
        ['type' => 'response.in_progress', 'response' => RESPONSES_USAGE_METADATA],
        ['type' => 'response.output_text.delta', 'delta' => 'yes'],
    ] as $event) {
        $body .= 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n";
    }
    $response = new MockResponse($body, ['response_headers' => ['x-request-id: req_3']]);
    $listener = responsesUsageListener();
    $provider = (new OpenAIResponsesProvider('requested-model', httpClient: new MockHttpClient($response)))
        ->withUsageListener($listener);

    $events = $provider->streamEvents([new UserMessage('Answer the question')]);
    expect($events->current())->toBeInstanceOf(TextDelta::class)
        ->and($listener->reports)->toBeEmpty();
    unset($events);

    expect($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expectResponsesUsageMetadata($report);
    expect($report->operation)->toBe('stream')
        ->and($report->model)->toBe('requested-model')
        ->and($report->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($report->failed)->toBeTrue();
});

test('OpenAIResponsesUsageReport concurrent structured fallback and chat preserve their operation labels', function () {
    $body = json_encode(RESPONSES_USAGE_METADATA, JSON_THROW_ON_ERROR);
    $chunks = static function () use ($body): Generator {
        yield substr($body, 0, 10);
        Fiber::suspend();
        yield substr($body, 10);
    };
    $client = new MockHttpClient([new MockResponse($chunks()), new MockResponse($body)]);
    $listener = responsesUsageListener();
    $provider = (new OpenAIResponsesProvider('requested-model', httpClient: $client))->withUsageListener($listener);
    $messages = [new UserMessage('Answer the question')];

    $structured = new Fiber(static fn () => $provider->structured($messages, 'invalid JSON'));
    $structured->start();
    expect($structured->isSuspended())->toBeTrue()
        ->and($listener->reports)->toBeEmpty();

    $chat = new Fiber(static fn () => $provider->chat($messages));
    $chat->start();
    $structured->resume();

    expect($chat->isTerminated())->toBeTrue()
        ->and($structured->isTerminated())->toBeTrue()
        ->and($client->getRequestsCount())->toBe(2)
        ->and(array_column($listener->reports, 'operation'))->toBe(['chat', 'structured']);
});
