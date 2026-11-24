<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ReasoningEffort;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\LlamaServerProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const CHAT_COMPLETIONS_REPORTED_USAGE = [
    'prompt_tokens' => 10,
    'completion_tokens' => 5,
    'prompt_tokens_details' => ['cached_tokens' => 4],
    'completion_tokens_details' => ['reasoning_tokens' => 2],
];

function chatCompletionsUsageListener(): UsageListener
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

function chatCompletionsUsageResponse(
    bool $suspend = false,
    string|array $content = '{"answer":"yes"}',
    string $finishReason = 'stop',
): MockResponse
{
    $body = json_encode([
        'id' => 'chatcmpl-2',
        'model' => 'vendor-model',
        'usage' => CHAT_COMPLETIONS_REPORTED_USAGE,
        'choices' => [['message' => ['content' => $content], 'finish_reason' => $finishReason]],
    ], JSON_THROW_ON_ERROR);
    $chunks = static function () use ($body): Generator {
        yield substr($body, 0, 12);
        \Fiber::suspend();
        yield substr($body, 12);
    };

    return new MockResponse($suspend ? $chunks() : $body, ['response_headers' => ['x-request-id: req_2']]);
}

test('OpenAICompatibleUsageReport structured via response_format and via fallback report once as structured', function (string $schema, bool $native) {
    $listener = chatCompletionsUsageListener();
    $response = chatCompletionsUsageResponse();
    $client = new MockHttpClient($response);
    $provider = (new OpenAICompatibleProvider('requested-model', httpClient: $client))->withUsageListener($listener);

    $result = $provider->structured([new UserMessage('Answer the question')], $schema);

    $body = json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
    expect(isset($body['response_format']))->toBe($native)
        ->and($body)->not->toHaveKey(UsageReport::OPERATION_OPTION)
        ->and($client->getRequestsCount())->toBe(1)
        ->and($result->content)->toBe('{"answer":"yes"}')
        ->and($listener->reports)->toHaveCount(1);

    $report = $listener->reports[0];
    expect($report->operation)->toBe('structured')
        ->and($report->requestId)->toBe('req_2')
        ->and($report->responseId)->toBe('chatcmpl-2')
        ->and($report->requestedModel)->toBe('requested-model')
        ->and($report->model)->toBe('vendor-model')
        ->and($report->usage)->toEqual(new Usage(10, 5, 15, cacheReadTokens: 4, reasoningTokens: 2))
        ->and($report->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($report->failed)->toBeFalse();
})->with([
    'response_format' => ['{"type":"object"}', true],
    'fallback' => ['invalid JSON', false],
]);

test('OpenAICompatibleUsageReport Ollama structured keeps injected options and reports structured', function () {
    $listener = chatCompletionsUsageListener();
    $response = chatCompletionsUsageResponse();
    $client = new MockHttpClient($response);
    $provider = (new OllamaProvider(httpClient: $client, reasoningEffort: ReasoningEffort::Low))
        ->withUsageListener($listener);

    $provider->structured([new UserMessage('Answer the question')], '{"type":"object"}');

    $body = json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
    expect($body['reasoning_effort'])->toBe('low')
        ->and($body)->not->toHaveKey(UsageReport::OPERATION_OPTION)
        ->and($client->getRequestsCount())->toBe(1)
        ->and($listener->reports)->toHaveCount(1)
        ->and($listener->reports[0]->operation)->toBe('structured');
});

test('OpenAICompatibleUsageReport Fibers sharing one instance label correctly', function () {
    $listener = chatCompletionsUsageListener();
    $client = new MockHttpClient([chatCompletionsUsageResponse(suspend: true), chatCompletionsUsageResponse()]);
    $provider = (new OpenAICompatibleProvider('requested-model', httpClient: $client))->withUsageListener($listener);
    $messages = [new UserMessage('Answer the question')];

    $structured = new \Fiber(static fn () => $provider->structured($messages, '{"type":"object"}'));
    $structured->start();
    expect($structured->isSuspended())->toBeTrue()
        ->and($listener->reports)->toBeEmpty();

    $chat = new \Fiber(static fn () => $provider->chat($messages));
    $chat->start();
    expect($chat->isTerminated())->toBeTrue();

    $structured->resume();
    expect($structured->isTerminated())->toBeTrue()
        ->and($client->getRequestsCount())->toBe(2)
        ->and(array_column($listener->reports, 'operation'))->toBe(['chat', 'structured']);
});

test('OpenAICompatibleUsageReport subclasses report with their own short class name', function (string $class, string $shortName, ?string $requestId) {
    $listener = chatCompletionsUsageListener();
    $client = new MockHttpClient(chatCompletionsUsageResponse());
    $provider = (new $class('requested-model', httpClient: $client))->withUsageListener($listener);

    $provider->chat([new UserMessage('Answer the question')]);

    expect($listener->reports)->toHaveCount(1)
        ->and($listener->reports[0]->provider)->toBe($shortName)
        ->and($listener->reports[0]->responseId)->toBe('chatcmpl-2')
        ->and($listener->reports[0]->requestId)->toBe($requestId);
})->with([
    [XAIProvider::class, 'XAIProvider', 'req_2'],
    [MistralProvider::class, 'MistralProvider', 'req_2'],
    [OllamaProvider::class, 'OllamaProvider', null],
    [LlamaServerProvider::class, 'LlamaServerProvider', null],
]);

test('OpenAICompatibleUsageReport streams retain received usage and report by Finish or abandonment', function (bool $abandon) {
    $listener = chatCompletionsUsageListener();
    $opening = [
        'id' => 'chatcmpl-2',
        'model' => 'vendor-model',
        'usage' => CHAT_COMPLETIONS_REPORTED_USAGE,
        'choices' => [['delta' => ['content' => 'yes']]],
    ];
    $closing = ['choices' => [['delta' => [], 'finish_reason' => 'stop']]];
    $body = '';
    foreach ([$opening, $closing] as $chunk) {
        $body .= 'data: ' . json_encode($chunk, JSON_THROW_ON_ERROR) . "\n\n";
    }
    $response = new MockResponse($body, ['response_headers' => ['x-request-id: req_2']]);
    $provider = (new OpenAICompatibleProvider('requested-model', httpClient: new MockHttpClient($response)))
        ->withUsageListener($listener);

    $events = $provider->streamEvents([new UserMessage('Answer the question')]);
    foreach ($events as $event) {
        if ($abandon && $event instanceof TextDelta) {
            expect($listener->reports)->toBeEmpty();
            break;
        }
        if ($event instanceof Finish) {
            expect($listener->reports)->toHaveCount(1)
                ->and($listener->reports[0]->usage)->toEqual($event->usage);
            break;
        }
    }
    expect($event)->toBeInstanceOf($abandon ? TextDelta::class : Finish::class);
    unset($events);

    expect($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expect($report->operation)->toBe('stream')
        ->and($report->requestId)->toBe('req_2')
        ->and($report->responseId)->toBe('chatcmpl-2')
        ->and($report->usage)->toEqual(new Usage(10, 5, 15, cacheReadTokens: 4, reasoningTokens: 2))
        ->and($report->model)->toBe($abandon ? 'requested-model' : 'vendor-model')
        ->and($report->finishReason)->toBe($abandon ? ProviderFinishReason::Error : ProviderFinishReason::Stop)
        ->and($report->failed)->toBe($abandon);
})->with(['complete' => [false], 'abandoned' => [true]]);

test('OpenAICompatibleUsageReport failed chat keeps response metadata and usage', function (bool $invalidContent) {
    $listener = chatCompletionsUsageListener();
    $response = $invalidContent
        ? chatCompletionsUsageResponse(content: ['unexpected'])
        : chatCompletionsUsageResponse(finishReason: 'error');
    $provider = (new OpenAICompatibleProvider('requested-model', httpClient: new MockHttpClient($response)))
        ->withUsageListener($listener);

    $chat = fn () => $provider->chat([new UserMessage('Answer the question')]);
    if ($invalidContent) {
        expect($chat)->toThrow(TypeError::class);
    } else {
        expect($chat()->finishReason)->toBe(ProviderFinishReason::Error);
    }

    expect($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expect($report->requestId)->toBe('req_2')
        ->and($report->responseId)->toBe('chatcmpl-2')
        ->and($report->usage)->toEqual(new Usage(10, 5, 15, cacheReadTokens: 4, reasoningTokens: 2))
        ->and($report->model)->toBe('requested-model')
        ->and($report->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($report->failed)->toBeTrue();
})->with(['invalid content' => [true], 'error finish' => [false]]);
