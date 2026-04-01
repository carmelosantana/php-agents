<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
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

test('stream payload omits OpenAI stream_options extension', function () {
    $requestPayload = null;
    $sseData = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]),
        'data: [DONE]',
        '',
    ]);

    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload, $sseData): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return new MockResponse($sseData, ['http_code' => 200]);
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    iterator_to_array($provider->stream([new UserMessage('Hello')], [], ['stream_options' => ['include_usage' => true]]));

    expect($requestPayload['stream'])->toBeTrue()
        ->and($requestPayload)->not->toHaveKey('stream_options');
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

// --- Tool Call ID Normalization ---

test('OpenAI-format tool call IDs are normalized to 9-char alphanumeric', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new SystemMessage('You are helpful.'),
        new UserMessage('Do something'),
        new AssistantMessage('', [new ToolCall('call_abc123def456ghi789xyz', 'read_file', ['path' => '/tmp/test'])]),
        new ToolResultMessage(ToolResult::success('file contents here')->withCallId('call_abc123def456ghi789xyz')),
        new UserMessage('Thanks'),
    ]);

    $messages = $requestPayload['messages'];

    // Assistant tool_calls[0].id must be exactly 9 alphanumeric chars
    $normalizedId = $messages[2]['tool_calls'][0]['id'];
    expect($normalizedId)->toMatch('/^[a-zA-Z0-9]{9}$/')
        ->and($normalizedId)->not->toBe('call_abc123def456ghi789xyz');

    // Tool result tool_call_id must match the normalized assistant ID
    expect($messages[3]['tool_call_id'])->toBe($normalizedId);
});

test('Anthropic-format tool call IDs are normalized to 9-char alphanumeric', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage('Do something'),
        new AssistantMessage('', [new ToolCall('toolu_01ABC123DEF456', 'read_file', ['path' => '/tmp/test'])]),
        new ToolResultMessage(ToolResult::success('result')->withCallId('toolu_01ABC123DEF456')),
    ]);

    $messages = $requestPayload['messages'];
    $normalizedId = $messages[1]['tool_calls'][0]['id'];

    expect($normalizedId)->toMatch('/^[a-zA-Z0-9]{9}$/')
        ->and($messages[2]['tool_call_id'])->toBe($normalizedId);
});

test('Gemini-format synthetic tool call IDs are normalized', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage('Do something'),
        new AssistantMessage('', [new ToolCall('g00000000', 'exec', ['cmd' => 'ls'])]),
        new ToolResultMessage(ToolResult::success('output')->withCallId('g00000000')),
    ]);

    $messages = $requestPayload['messages'];
    $normalizedId = $messages[1]['tool_calls'][0]['id'];

    // g00000000 is already 9 chars alphanumeric — should pass through unchanged
    expect($normalizedId)->toBe('g00000000')
        ->and($messages[2]['tool_call_id'])->toBe('g00000000');
});

test('already-valid 9-char alphanumeric IDs pass through unchanged', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage('Do something'),
        new AssistantMessage('', [new ToolCall('AbCdEf123', 'read_file', ['path' => '/tmp'])]),
        new ToolResultMessage(ToolResult::success('contents')->withCallId('AbCdEf123')),
    ]);

    $messages = $requestPayload['messages'];

    expect($messages[1]['tool_calls'][0]['id'])->toBe('AbCdEf123')
        ->and($messages[2]['tool_call_id'])->toBe('AbCdEf123');
});

test('multiple tool calls in one assistant message are each normalized', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $id1 = 'call_longid_first_one_here';
    $id2 = 'call_longid_second_one_here';

    $provider->chat([
        new UserMessage('Do two things'),
        new AssistantMessage('', [
            new ToolCall($id1, 'read_file', ['path' => '/a']),
            new ToolCall($id2, 'write_file', ['path' => '/b']),
        ]),
        new ToolResultMessage(ToolResult::success('result a')->withCallId($id1)),
        new ToolResultMessage(ToolResult::success('result b')->withCallId($id2)),
    ]);

    $messages = $requestPayload['messages'];
    $normId1 = $messages[1]['tool_calls'][0]['id'];
    $normId2 = $messages[1]['tool_calls'][1]['id'];

    // Both must be valid format
    expect($normId1)->toMatch('/^[a-zA-Z0-9]{9}$/')
        ->and($normId2)->toMatch('/^[a-zA-Z0-9]{9}$/');

    // They must be different from each other
    expect($normId1)->not->toBe($normId2);

    // Tool results must use the matching normalized IDs
    expect($messages[2]['tool_call_id'])->toBe($normId1)
        ->and($messages[3]['tool_call_id'])->toBe($normId2);
});

test('normalization is deterministic — same input produces same output', function () {
    $payloads = [];
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$payloads): MockResponse {
        $payloads[] = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $messages = [
        new UserMessage('Do something'),
        new AssistantMessage('', [new ToolCall('call_abc123def456ghi789xyz', 'read_file', ['path' => '/tmp'])]),
        new ToolResultMessage(ToolResult::success('result')->withCallId('call_abc123def456ghi789xyz')),
    ];

    // Call twice with the same messages
    $provider->chat($messages);
    $provider->chat($messages);

    // Both should produce identical normalized IDs
    $id1 = $payloads[0]['messages'][1]['tool_calls'][0]['id'];
    $id2 = $payloads[1]['messages'][1]['tool_calls'][0]['id'];

    expect($id1)->toBe($id2);
});

test('messages without tool calls pass through unchanged', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockMistralResponse();
    });

    $provider = new MistralProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new SystemMessage('You are helpful.'),
        new UserMessage('Hello'),
        new AssistantMessage('Hi there!'),
        new UserMessage('How are you?'),
    ]);

    $messages = $requestPayload['messages'];

    expect($messages[0]['role'])->toBe('system')
        ->and($messages[0]['content'])->toBe('You are helpful.')
        ->and($messages[1]['role'])->toBe('user')
        ->and($messages[1]['content'])->toBe('Hello')
        ->and($messages[2]['role'])->toBe('assistant')
        ->and($messages[2]['content'])->toBe('Hi there!')
        ->and($messages[3]['role'])->toBe('user')
        ->and($messages[3]['content'])->toBe('How are you?');
});
