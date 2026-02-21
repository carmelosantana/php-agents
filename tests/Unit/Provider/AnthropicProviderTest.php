<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Helper: build a mock Anthropic API response.
 *
 * @param array<string, mixed> $overrides
 */
function mockAnthropicResponse(array $overrides = []): MockResponse
{
    $body = json_encode(array_merge([
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-20250514',
        'content' => [['type' => 'text', 'text' => 'Hello!']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ], $overrides));

    return new MockResponse($body, ['http_code' => 200]);
}

test('chat extracts string system prompt correctly', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([
        new SystemMessage('You are a helpful assistant.'),
        new UserMessage('Hi'),
    ]);

    expect($requestPayload)->not->toBeNull()
        ->and($requestPayload['system'])->toBe('You are a helpful assistant.')
        ->and($requestPayload['messages'])->toHaveCount(1)
        ->and($requestPayload['messages'][0]['role'])->toBe('user');
});

test('chat preserves falsy string system prompt', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    // "0" is a falsy string — the fix ensures it is NOT discarded
    $response = $provider->chat([
        new SystemMessage('0'),
        new UserMessage('Hi'),
    ]);

    expect($requestPayload)->not->toBeNull()
        ->and($requestPayload['system'])->toBe('0');
});

test('chat omits system field when no system message provided', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([
        new UserMessage('Hi'),
    ]);

    expect($requestPayload)->not->toBeNull()
        ->and($requestPayload)->not->toHaveKey('system');
});

test('chat sends correct Anthropic headers', function () {
    $capturedHeaders = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
        $capturedHeaders = $options['headers'] ?? $options['normalized_headers'] ?? [];
        return mockAnthropicResponse();
    });

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'sk-ant-test-key',
        httpClient: $mockClient,
    );

    $provider->chat([new UserMessage('Hi')]);

    // MockHttpClient normalizes headers to lowercase arrays
    expect($capturedHeaders)->toBeArray();
});

test('chat parses response content and usage', function () {
    $mockClient = new MockHttpClient([
        mockAnthropicResponse([
            'content' => [['type' => 'text', 'text' => 'The answer is 42.']],
            'usage' => ['input_tokens' => 15, 'output_tokens' => 8],
        ]),
    ]);

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('What is the meaning of life?')]);

    expect($response->content)->toBe('The answer is 42.')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage)->not->toBeNull()
        ->and($response->usage->promptTokens)->toBe(15)
        ->and($response->usage->completionTokens)->toBe(8)
        ->and($response->usage->totalTokens)->toBe(23);
});

test('chat parses tool use response', function () {
    $mockClient = new MockHttpClient([
        mockAnthropicResponse([
            'content' => [
                ['type' => 'text', 'text' => 'Let me search for that.'],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_123',
                    'name' => 'brave_search',
                    'input' => ['query' => 'PHP 8.4 features'],
                ],
            ],
            'stop_reason' => 'tool_use',
        ]),
    ]);

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Search for PHP 8.4 features')]);

    expect($response->content)->toBe('Let me search for that.')
        ->and($response->finishReason)->toBe(FinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('brave_search')
        ->and($response->toolCalls[0]->id)->toBe('toolu_123')
        ->and($response->toolCalls[0]->arguments)->toBe(['query' => 'PHP 8.4 features']);
});

test('isAvailable returns false with empty API key', function () {
    $provider = new AnthropicProvider(apiKey: '');
    expect($provider->isAvailable())->toBeFalse();
});

test('isAvailable returns false with invalid API key (no real API)', function () {
    $provider = new AnthropicProvider(apiKey: 'sk-ant-test-invalid');
    // isAvailable() now makes a real HTTP request, so a fake key returns false
    expect($provider->isAvailable())->toBeFalse();
});

test('models returns list of known Anthropic models', function () {
    $provider = new AnthropicProvider(apiKey: 'test');
    $models = $provider->models();

    expect($models)->toBeArray()
        ->and($models)->not->toBeEmpty()
        ->and($models[0]->provider)->toBe('anthropic');
});

test('chat merges consecutive same-role messages with mixed content types', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    // Two consecutive user messages — one string content, one structured.
    // This simulates what happens after conversation pruning drops turns.
    $provider->chat([
        new SystemMessage('system'),
        new UserMessage('first question'),
        new UserMessage('second question'),
    ]);

    expect($requestPayload)->not->toBeNull();
    // Should be merged into a single user message
    expect($requestPayload['messages'])->toHaveCount(1);
    expect($requestPayload['messages'][0]['role'])->toBe('user');
});

test('chat merges consecutive assistant text messages', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });

    $provider = new AnthropicProvider(
        model: 'claude-sonnet-4-20250514',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    // User question then two consecutive assistant messages
    $provider->chat([
        new UserMessage('question'),
        new AssistantMessage('part one'),
        new AssistantMessage('part two'),
        new UserMessage('follow up'),
    ]);

    expect($requestPayload)->not->toBeNull();
    // Should be: user, merged-assistant, user = 3 messages
    expect($requestPayload['messages'])->toHaveCount(3);
    expect($requestPayload['messages'][0]['role'])->toBe('user');
    expect($requestPayload['messages'][1]['role'])->toBe('assistant');
    expect($requestPayload['messages'][2]['role'])->toBe('user');
});
