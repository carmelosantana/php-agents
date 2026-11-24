<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const GEMINI_USAGE_METADATA = [
    'responseId' => 'gem_5',
    'modelVersion' => 'vendor-model',
    'usageMetadata' => [
        'promptTokenCount' => 12,
        'candidatesTokenCount' => 3,
        'thoughtsTokenCount' => 2,
        'cachedContentTokenCount' => 4,
        'totalTokenCount' => 17,
    ],
];

function geminiUsageListener(): UsageListener
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

function geminiUsageResponse(): MockResponse
{
    return new MockResponse(json_encode([
        ...GEMINI_USAGE_METADATA,
        'candidates' => [['content' => ['parts' => [['text' => '{"answer":"yes"}']]], 'finishReason' => 'STOP']],
    ], JSON_THROW_ON_ERROR));
}

function expectGeminiUsageMetadata(UsageReport $report): void
{
    expect($report->requestId)->toBeNull()
        ->and($report->responseId)->toBe('gem_5')
        ->and($report->requestedModel)->toBe('requested-model')
        ->and($report->usage)->toEqual(new Usage(12, 5, 17, cacheReadTokens: 4, reasoningTokens: 2));
}

test('GeminiUsageReport all three structured exits report once as structured', function (string $schema, bool $native) {
    $response = geminiUsageResponse();
    $client = new MockHttpClient($response);
    $listener = geminiUsageListener();
    $provider = (new GeminiProvider('requested-model', httpClient: $client))->withUsageListener($listener);

    $result = $provider->structured([new UserMessage('Answer the question')], $schema);

    $body = json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->content)->toBe('{"answer":"yes"}')
        ->and(isset($body['generationConfig']['responseSchema']))->toBe($native)
        ->and($response->getRequestOptions()['body'])->not->toContain(UsageReport::OPERATION_OPTION)
        ->and($client->getRequestsCount())->toBe(1)
        ->and($listener->reports)->toHaveCount(1);

    $report = $listener->reports[0];
    expectGeminiUsageMetadata($report);
    expect($report->operation)->toBe('structured')
        ->and($report->model)->toBe('vendor-model')
        ->and($report->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($report->failed)->toBeFalse();
})->with([
    'response schema' => ['{"type":"object"}', true],
    'invalid JSON' => ['invalid JSON', false],
    'non-object schema' => ['{"schema":"string"}', false],
]);

test('GeminiUsageReport image download sends no report', function () {
    $image = new MockResponse("\x89PNG", ['response_headers' => ['content-type: image/png']]);
    $imageClient = new MockHttpClient($image);
    $response = geminiUsageResponse();
    $client = new MockHttpClient($response);
    $listener = geminiUsageListener();
    $provider = new GeminiProvider('requested-model', httpClient: $client, imageHttpClient: $imageClient);
    (new ReflectionProperty(GeminiProvider::class, 'hostResolver'))->setValue($provider, static fn () => ['93.184.216.34']);

    $provider->withUsageListener($listener)->chat([
        new UserMessage([['type' => 'image_url', 'image_url' => ['url' => 'https://img.test/a.png']]]),
    ]);

    $body = json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
    expect($imageClient->getRequestsCount())->toBe(1)
        ->and($image->getRequestMethod())->toBe('GET')
        ->and($body['contents'][0]['parts'][0]['inlineData']['data'])->toBe(base64_encode("\x89PNG"))
        ->and($client->getRequestsCount())->toBe(1)
        ->and($listener->reports)->toHaveCount(1);
    expectGeminiUsageMetadata($listener->reports[0]);
    expect($listener->reports[0]->operation)->toBe('chat');
});

test('GeminiUsageReport abandoned streams retain usage already received', function () {
    $chunk = [
        ...GEMINI_USAGE_METADATA,
        'candidates' => [['content' => ['parts' => [['text' => 'yes']]]]],
    ];
    $client = new MockHttpClient(new MockResponse('data: ' . json_encode($chunk, JSON_THROW_ON_ERROR) . "\n\n"));
    $listener = geminiUsageListener();
    $provider = (new GeminiProvider('requested-model', httpClient: $client))->withUsageListener($listener);

    $stream = $provider->streamEvents([new UserMessage('Answer the question')]);
    expect($stream->current())->toBeInstanceOf(TextDelta::class)
        ->and($listener->reports)->toBeEmpty();
    unset($stream);

    expect($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expectGeminiUsageMetadata($report);
    expect($report->operation)->toBe('stream')
        ->and($report->model)->toBe('requested-model')
        ->and($report->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($report->failed)->toBeTrue();
});

test('GeminiUsageReport preserves the response parse error when usage is invalid', function () {
    $body = json_encode([
        ...GEMINI_USAGE_METADATA,
        'usageMetadata' => ['candidatesTokenCount' => PHP_INT_MAX, 'thoughtsTokenCount' => 1],
        'candidates' => [['finishReason' => []]],
    ], JSON_THROW_ON_ERROR);
    $listener = geminiUsageListener();
    $provider = (new GeminiProvider('requested-model', httpClient: new MockHttpClient(new MockResponse($body))))
        ->withUsageListener($listener);
    $messages = [new UserMessage('Answer the question')];

    expect(fn () => $provider->chat($messages))->toThrow(TypeError::class, 'mapGeminiFinishReason');
    expect($listener->reports)->toHaveCount(1);
    $report = $listener->reports[0];
    expect($report->usage)->toBeNull()
        ->and($report->responseId)->toBe('gem_5')
        ->and($report->model)->toBe('requested-model')
        ->and($report->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($report->failed)->toBeTrue();
});
