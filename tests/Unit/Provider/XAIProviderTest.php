<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Helper: build a mock OpenAI-format API response (xAI uses the same format).
 *
 * @param array<string, mixed> $overrides
 */
function mockXAIResponse(array $overrides = []): MockResponse
{
    $body = json_encode(array_merge([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => 'grok-4',
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from Grok!',
                ],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
        ],
    ], $overrides));

    return new MockResponse($body, ['http_code' => 200]);
}

test('chat sends correct payload and parses response', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([
        new SystemMessage('You are helpful.'),
        new UserMessage('Hi'),
    ]);

    expect($requestPayload)->not->toBeNull()
        ->and($requestPayload['model'])->toBe('grok-4')
        ->and($response->content)->toBe('Hello from Grok!')
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($response->usage->totalTokens)->toBe(15);
});

test('string content messages pass through unmodified', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([new UserMessage('plain text message')]);

    expect($requestPayload['messages'][0]['content'])->toBe('plain text message');
});

test('text content blocks are converted to input_text', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-2-vision-1212',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    // Send a multimodal message with text block
    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'What is in this image?'],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content)->toBeArray()
        ->and($content[0]['type'])->toBe('input_text')
        ->and($content[0]['text'])->toBe('What is in this image?');
});

test('image_url blocks are converted to input_image with nested url', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-2-vision-1212',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Describe this'],
            [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=', 'detail' => 'high'],
            ],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content)->toHaveCount(2)
        ->and($content[0]['type'])->toBe('input_text')
        ->and($content[0]['text'])->toBe('Describe this')
        ->and($content[1]['type'])->toBe('input_image')
        ->and($content[1]['image_url'])->toBe('data:image/png;base64,iVBORw0KGgo=')
        ->and($content[1]['detail'])->toBe('high');
});

test('image_url blocks with URL string are converted correctly', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-2-vision-1212',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage([
            [
                'type' => 'image_url',
                'image_url' => ['url' => 'https://example.com/photo.jpg'],
            ],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['type'])->toBe('input_image')
        ->and($content[0]['image_url'])->toBe('https://example.com/photo.jpg');
});

test('mixed content preserves ordering', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-2-vision-1212',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'first'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,abc']],
            ['type' => 'text', 'text' => 'second'],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content)->toHaveCount(3)
        ->and($content[0]['type'])->toBe('input_text')
        ->and($content[0]['text'])->toBe('first')
        ->and($content[1]['type'])->toBe('input_image')
        ->and($content[2]['type'])->toBe('input_text')
        ->and($content[2]['text'])->toBe('second');
});

test('empty content array passes through', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([new UserMessage([])]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content)->toBeArray()->and($content)->toBeEmpty();
});

test('tool result json helper stays string-only in xai payloads', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage('Read the file'),
        new AssistantMessage('', [new ToolCall('call_1', 'read_file', ['path' => '/tmp/test'])]),
        new ToolResultMessage(
            ToolResult::json(['status' => 'ok', 'path' => '/tmp/test'], ['phase' => 'read'])
                ->withMimeType('application/json')
                ->withCallId('call_1'),
        ),
    ]);

    $toolMessage = $requestPayload['messages'][2];

    expect($toolMessage['role'])->toBe('tool')
        ->and($toolMessage['tool_call_id'])->toBe('call_1')
        ->and($toolMessage['content'])->toContain('"status": "ok"')
        ->and($toolMessage['content'])->toContain('"path": "/tmp/test"')
        ->and($toolMessage)->not->toHaveKey('metadata')
        ->and($toolMessage)->not->toHaveKey('mimeType');
});

test('chat parses tool call response', function () {
    $mockClient = new MockHttpClient([
        mockXAIResponse([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_abc123',
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

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Weather in Paris')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[0]->arguments)->toBe(['city' => 'Paris']);
});

test('default base URL is xAI endpoint', function () {
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([new UserMessage('Hi')]);

    expect($capturedUrl)->toContain('https://api.x.ai/v1/chat/completions');
});

test('image_url block without detail defaults to high', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-2-vision-1212',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/img.png']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['detail'])->toBe('high');
});

test('unknown content block types pass through unchanged', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage([
            ['type' => 'custom_type', 'data' => 'something'],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['type'])->toBe('custom_type')
        ->and($content[0]['data'])->toBe('something');
});

test('response with no usage data returns null usage', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'grok-4',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Hi'],
                    'finish_reason' => 'stop',
                ],
            ],
        ]), ['http_code' => 200]),
    ]);

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Hi')]);

    expect($response->content)->toBe('Hi')
        ->and($response->usage)->toBeNull();
});

test('system message is included in formatted messages', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockXAIResponse();
    });

    $provider = new XAIProvider(
        model: 'grok-4',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new SystemMessage('Be concise'),
        new UserMessage('Hello'),
    ]);

    // System messages are passed through as standard OpenAI format
    expect($requestPayload['messages'])->toHaveCount(2)
        ->and($requestPayload['messages'][0]['role'])->toBe('system')
        ->and($requestPayload['messages'][0]['content'])->toBe('Be concise');
});
