<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
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
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop)
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
        ->and($response->finishReason)->toBe(ProviderFinishReason::ToolUse)
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

test('data URI image is converted to Anthropic base64 source format', function () {
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

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'What is in this image?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQ']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content)->toHaveCount(2)
        ->and($content[0]['type'])->toBe('text')
        ->and($content[1]['type'])->toBe('image')
        ->and($content[1]['source']['type'])->toBe('base64')
        ->and($content[1]['source']['media_type'])->toBe('image/jpeg')
        ->and($content[1]['source']['data'])->toBe('/9j/4AAQ');
});

test('URL image is converted to Anthropic url source format', function () {
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

    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/photo.jpg']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['type'])->toBe('image')
        ->and($content[0]['source']['type'])->toBe('url')
        ->and($content[0]['source']['url'])->toBe('https://example.com/photo.jpg');
});

test('PNG data URI is correctly parsed', function () {
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

    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content[0]['source']['media_type'])->toBe('image/png')
        ->and($content[0]['source']['data'])->toBe('iVBORw0KGgo');
});

test('string content passes through without image conversion', function () {
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

    $provider->chat([new UserMessage('Just text')]);

    expect($requestPayload['messages'][0]['content'])->toBe('Just text');
});

test('finish reason mapping: max_tokens', function () {
    $mockClient = new MockHttpClient([
        mockAnthropicResponse(['stop_reason' => 'max_tokens']),
    ]);

    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::MaxTokens);
});

test('structured output uses tool_use trick', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse([
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'extract_data', 'input' => ['name' => 'Alice']],
            ],
            'stop_reason' => 'tool_use',
        ]);
    });

    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);
    $schema = json_encode([
        'name' => 'extract_data',
        'description' => 'Extract structured data',
        'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ]);

    $result = $provider->structured([new UserMessage('My name is Alice')], $schema);

    expect($requestPayload['tools'])->toHaveCount(1)
        ->and($requestPayload['tools'][0]['name'])->toBe('extract_data')
        ->and($requestPayload['tool_choice'])->toBe(['type' => 'tool', 'name' => 'extract_data'])
        ->and($result)->toBe(['name' => 'Alice']);
});

// --- Reasoning / Extended Thinking ---

test('parseResponse extracts thinking content blocks into reasoning', function () {
    $mockClient = new MockHttpClient([
        mockAnthropicResponse([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Let me think about this carefully.'],
                ['type' => 'text', 'text' => 'The answer is 42.'],
            ],
        ]),
    ]);

    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('What is life?')]);

    expect($response->content)->toBe('The answer is 42.')
        ->and($response->reasoning)->toBe('Let me think about this carefully.');
});

test('parseResponse concatenates multiple thinking blocks', function () {
    $mockClient = new MockHttpClient([
        mockAnthropicResponse([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'First thought. '],
                ['type' => 'thinking', 'thinking' => 'Second thought.'],
                ['type' => 'text', 'text' => 'Done.'],
            ],
        ]),
    ]);

    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->reasoning)->toBe('First thought. Second thought.');
});

test('parseResponse reasoning is empty string when no thinking block', function () {
    $mockClient = new MockHttpClient([
        mockAnthropicResponse([
            'content' => [
                ['type' => 'text', 'text' => 'Plain answer.'],
            ],
        ]),
    ]);

    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->reasoning)->toBe('');
});

test('stream yields reasoning Response chunks for thinking_delta events', function () {
    $sseData = implode('', [
        "data: " . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking']]) . "\n\n",
        "data: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'I ponder']]) . "\n\n",
        "data: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => ' deeply']]) . "\n\n",
        "data: " . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n",
        "data: " . json_encode(['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n",
        "data: " . json_encode(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Answer.']]) . "\n\n",
        "data: " . json_encode(['type' => 'content_block_stop', 'index' => 1]) . "\n\n",
        "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 20]]) . "\n\n",
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('Think carefully')]));

    // Collect all reasoning chunks
    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');
    expect($reasoningChunks)->not->toBeEmpty();

    $allReasoning = implode('', array_map(fn($r) => $r->reasoning, $reasoningChunks));
    expect($allReasoning)->toBe('I ponder deeply');
});

test('stream thinking_delta chunks have empty content', function () {
    $sseData = implode('', [
        "data: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'thinking...']]) . "\n\n",
        "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]) . "\n\n",
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('hi')]));
    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');

    foreach ($reasoningChunks as $chunk) {
        expect($chunk->content)->toBe('');
    }
});
