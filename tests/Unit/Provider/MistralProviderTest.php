<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function mockMistralResponse(array $overrides = []): MockResponse
{
    $body = json_encode(array_merge([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello from Mistral!'],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], $overrides));

    return new MockResponse($body, ['http_code' => 200]);
}

test('basic chat returns correct response', function () {
    $mockClient = new MockHttpClient([mockMistralResponse()]);

    $provider = new MistralProvider(
        model: 'mistral-large-latest',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Hi')]);

    expect($response->content)->toBe('Hello from Mistral!')
        ->and($response->finishReason)->toBe(FinishReason::Stop);
});

test('default base URL is Mistral API', function () {
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($capturedUrl)->toContain('https://api.mistral.ai/v1');
});

test('nested image_url objects are flattened to strings', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Describe this image'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png', 'detail' => 'high']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['type'])->toBe('text')
        ->and($content[1]['type'])->toBe('image_url')
        ->and($content[1]['image_url'])->toBe('https://example.com/image.png');
});

test('already-flat image_url string passes through unchanged', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => 'data:image/png;base64,iVBOR'],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['image_url'])->toBe('data:image/png;base64,iVBOR');
});

test('base64 data URI in nested object is flattened', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQ']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['image_url'])->toBe('data:image/jpeg;base64,/9j/4AAQ');
});

test('text blocks pass through unchanged', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Hello world'],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0])->toBe(['type' => 'text', 'text' => 'Hello world']);
});

test('string content messages pass through without modification', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([new UserMessage('Just text')]);

    expect($requestPayload['messages'][0]['content'])->toBe('Just text');
});

test('system messages work normally', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new SystemMessage('You are helpful.'),
        new UserMessage('Hi'),
    ]);

    expect($requestPayload['messages'][0]['role'])->toBe('system')
        ->and($requestPayload['messages'][1]['role'])->toBe('user');
});

test('tool calls in response are parsed', function () {
    $mockClient = new MockHttpClient([
        mockMistralResponse([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"city":"Paris"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ]),
    ]);

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('Weather?')]);

    expect($response->finishReason)->toBe(FinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
});

test('mixed content with multiple images converts correctly', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Compare these images'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://a.com/1.jpg']],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,abc']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content)->toHaveCount(3)
        ->and($content[0]['type'])->toBe('text')
        ->and($content[1]['image_url'])->toBe('https://a.com/1.jpg')
        ->and($content[2]['image_url'])->toBe('data:image/png;base64,abc');
});

// --- Magistral / Reasoning (array content format) ---

test('parseResponse handles Magistral array content with thinking and text blocks', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'id' => 'chatcmpl-magistral',
            'object' => 'chat.completion',
            'model' => 'magistral-small-latest',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'thinking',
                            'thinking' => [
                                ['type' => 'text', 'text' => 'Let me reason about this. '],
                                ['type' => 'text', 'text' => 'Step two.'],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'The final answer.',
                        ],
                    ],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 30, 'total_tokens' => 80],
        ]), ['http_code' => 200]),
    ]);

    $provider = new MistralProvider(model: 'magistral-small-latest', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('Explain something complex')]);

    expect($response->content)->toBe('The final answer.')
        ->and($response->reasoning)->toBe('Let me reason about this. Step two.');
});

test('parseResponse Magistral reasoning is empty when no thinking block', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'id' => 'chatcmpl-magistral',
            'object' => 'chat.completion',
            'model' => 'magistral-small-latest',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'text', 'text' => 'Simple answer.'],
                    ],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]), ['http_code' => 200]),
    ]);

    $provider = new MistralProvider(model: 'magistral-small-latest', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->content)->toBe('Simple answer.')
        ->and($response->reasoning)->toBe('');
});

test('parseResponse standard string content delegates to parent (no array content)', function () {
    $mockClient = new MockHttpClient([mockMistralResponse(['choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => 'Plain string response.'],
        'finish_reason' => 'stop',
    ]]])]);

    $provider = new MistralProvider(model: 'mistral-large-latest', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->content)->toBe('Plain string response.')
        ->and($response->reasoning)->toBe('');
});

test('parseResponse Magistral usage is extracted correctly', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'id' => 'chatcmpl-magistral',
            'object' => 'chat.completion',
            'model' => 'magistral-small-latest',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'pondering']]],
                        ['type' => 'text', 'text' => 'Answer.'],
                    ],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ]), ['http_code' => 200]),
    ]);

    $provider = new MistralProvider(model: 'magistral-small-latest', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage)->not->toBeNull()
        ->and($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->totalTokens)->toBe(150);
});
