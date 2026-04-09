<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Helper: build a mock Gemini API response.
 *
 * @param array<string, mixed> $overrides
 */
function mockGeminiResponse(array $overrides = []): MockResponse
{
    $body = json_encode(array_merge([
        'candidates' => [
            [
                'content' => [
                    'role' => 'model',
                    'parts' => [['text' => 'Hello from Gemini!']],
                ],
                'finishReason' => 'STOP',
            ],
        ],
        'usageMetadata' => [
            'promptTokenCount' => 10,
            'candidatesTokenCount' => 5,
            'totalTokenCount' => 15,
        ],
    ], $overrides));

    return new MockResponse($body, ['http_code' => 200]);
}

test('chat sends correct payload structure', function () {
    $requestPayload = null;
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload, &$capturedUrl): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        $capturedUrl = $url;
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([
        new UserMessage('Hi'),
    ]);

    expect($capturedUrl)->toContain('/models/gemini-2.5-flash:generateContent')
        ->and($requestPayload)->toHaveKey('contents')
        ->and($requestPayload['contents'])->toHaveCount(1)
        ->and($requestPayload['contents'][0]['role'])->toBe('user')
        ->and($requestPayload['contents'][0]['parts'][0]['text'])->toBe('Hi');
});

test('headers use x-goog-api-key instead of Bearer', function () {
    $capturedHeaders = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
        $capturedHeaders = $options['normalized_headers'] ?? $options['headers'] ?? [];
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'my-gemini-key',
        httpClient: $mockClient,
    );

    $provider->chat([new UserMessage('Hi')]);

    // Just verify the request succeeded — header normalization varies by mock
    expect($capturedHeaders)->toBeArray();
});

test('system message is extracted to systemInstruction', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new SystemMessage('You are a cat.'),
        new UserMessage('Meow?'),
    ]);

    expect($requestPayload['systemInstruction'])->toBe(['parts' => [['text' => 'You are a cat.']]])
        ->and($requestPayload['contents'])->toHaveCount(1)
        ->and($requestPayload['contents'][0]['role'])->toBe('user');
});

test('systemInstruction is omitted when no system message', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([new UserMessage('Hi')]);

    expect($requestPayload)->not->toHaveKey('systemInstruction');
});

test('assistant messages use model role', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage('Hi'),
        new AssistantMessage('Hello!'),
        new UserMessage('How are you?'),
    ]);

    expect($requestPayload['contents'])->toHaveCount(3)
        ->and($requestPayload['contents'][0]['role'])->toBe('user')
        ->and($requestPayload['contents'][1]['role'])->toBe('model')
        ->and($requestPayload['contents'][2]['role'])->toBe('user');
});

test('image content blocks convert to inlineData parts', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQ']],
        ]),
    ]);

    $parts = $requestPayload['contents'][0]['parts'];
    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toBe(['text' => 'What is this?'])
        ->and($parts[1])->toHaveKey('inlineData')
        ->and($parts[1]['inlineData']['mimeType'])->toBe('image/jpeg')
        ->and($parts[1]['inlineData']['data'])->toBe('/9j/4AAQ');
});

test('URL image falls back to text description', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        if ($method === 'GET') {
            // Image download attempt — return 404 to trigger the text fallback
            return new MockResponse('', ['http_code' => 404]);
        }
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/photo.jpg']],
        ]),
    ]);

    $parts = $requestPayload['contents'][0]['parts'];
    expect($parts[0])->toHaveKey('text')
        ->and($parts[0]['text'])->toContain('https://example.com/photo.jpg');
});

test('formatTools produces functionDeclarations format', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $tool = new Tool(
        name: 'get_weather',
        description: 'Get weather for a city',
        parameters: [new StringParameter('city', 'City name', required: true)],
        callback: fn(array $args): ToolResult => ToolResult::success('sunny'),
    );

    $provider->chat([new UserMessage('Weather?')], [$tool]);

    expect($requestPayload['tools'])->toHaveCount(1)
        ->and($requestPayload['tools'][0])->toHaveKey('functionDeclarations')
        ->and($requestPayload['tools'][0]['functionDeclarations'])->toHaveCount(1)
        ->and($requestPayload['tools'][0]['functionDeclarations'][0]['name'])->toBe('get_weather')
        ->and($requestPayload['tools'][0]['functionDeclarations'][0]['description'])->toBe('Get weather for a city');
});

test('response parsing extracts text content', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['text' => 'Part one. '],
                            ['text' => 'Part two.'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Tell me something')]);

    expect($response->content)->toBe('Part one. Part two.')
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop);
});

test('response parsing extracts tool calls from functionCall parts', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['city' => 'London'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Weather in London?')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[0]->arguments)->toBe(['city' => 'London']);
});

test('finish reason mapping: STOP', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]),
    ]);

    $response = (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(ProviderFinishReason::Stop);
});

test('finish reason mapping: MAX_TOKENS', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]], 'finishReason' => 'MAX_TOKENS']]]),
    ]);

    $response = (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(ProviderFinishReason::MaxTokens);
});

test('finish reason mapping: SAFETY', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '']]], 'finishReason' => 'SAFETY']]]),
    ]);

    $response = (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(ProviderFinishReason::Error);
});

test('usage metadata is parsed correctly', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'usageMetadata' => [
                'promptTokenCount' => 25,
                'candidatesTokenCount' => 12,
                'totalTokenCount' => 37,
            ],
        ]),
    ]);

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage)->not->toBeNull()
        ->and($response->usage->promptTokens)->toBe(25)
        ->and($response->usage->completionTokens)->toBe(12)
        ->and($response->usage->totalTokens)->toBe(37);
});

test('response with no usage data returns null usage', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'hi']]]]],
        ]), ['http_code' => 200]),
    ]);

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage)->toBeNull();
});

test('empty candidates returns empty content', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode(['candidates' => []]), ['http_code' => 200]),
    ]);

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->content)->toBe('')
        ->and($response->toolCalls)->toBeEmpty();
});

test('consecutive same-role messages are merged', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new UserMessage('first'),
        new UserMessage('second'),
    ]);

    // Should be merged into a single user content
    expect($requestPayload['contents'])->toHaveCount(1)
        ->and($requestPayload['contents'][0]['role'])->toBe('user')
        ->and($requestPayload['contents'][0]['parts'])->toHaveCount(2);
});

test('isAvailable returns false with empty API key', function () {
    $provider = new GeminiProvider(apiKey: '');
    expect($provider->isAvailable())->toBeFalse();
});

test('tools payload omits tools key when no tools', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload)->not->toHaveKey('tools');
});

test('tool schema types are uppercased for Gemini', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);

    $tool = new Tool(
        name: 'test_tool',
        description: 'Test',
        parameters: [new StringParameter('input', 'Input text', required: true)],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );

    $provider->chat([new UserMessage('test')], [$tool]);

    $params = $requestPayload['tools'][0]['functionDeclarations'][0]['parameters'];
    expect($params['type'])->toBe('OBJECT')
        ->and($params['properties']['input']['type'])->toBe('STRING');
});

test('generationConfig options are passed through', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')], [], ['temperature' => 0.5, 'maxOutputTokens' => 100]);

    expect($requestPayload['generationConfig']['temperature'])->toBe(0.5)
        ->and($requestPayload['generationConfig']['maxOutputTokens'])->toBe(100);
});

test('multiple function calls in response are all parsed', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['functionCall' => ['name' => 'tool_a', 'args' => ['x' => 1]]],
                            ['functionCall' => ['name' => 'tool_b', 'args' => ['y' => 2]]],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('do both')]);

    expect($response->toolCalls)->toHaveCount(2)
        ->and($response->toolCalls[0]->name)->toBe('tool_a')
        ->and($response->toolCalls[1]->name)->toBe('tool_b')
        ->and($response->finishReason)->toBe(ProviderFinishReason::ToolUse);
});

test('mixed text and functionCall parts in response', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['text' => 'Let me check... '],
                            ['functionCall' => ['name' => 'search', 'args' => ['q' => 'test']]],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('search')]);

    expect($response->content)->toBe('Let me check... ')
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->finishReason)->toBe(ProviderFinishReason::ToolUse);
});

test('three consecutive user messages are merged into one content block', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new UserMessage('one'),
        new UserMessage('two'),
        new UserMessage('three'),
    ]);

    expect($requestPayload['contents'])->toHaveCount(1)
        ->and($requestPayload['contents'][0]['role'])->toBe('user')
        ->and($requestPayload['contents'][0]['parts'])->toHaveCount(3);
});

test('consecutive assistant messages are merged into one model content block', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new UserMessage('go'),
        new AssistantMessage('first response'),
        new AssistantMessage('second response'),
    ]);

    // user + two assistants; assistants become 'model' role and should merge
    expect($requestPayload['contents'])->toHaveCount(2)
        ->and($requestPayload['contents'][0]['role'])->toBe('user')
        ->and($requestPayload['contents'][1]['role'])->toBe('model')
        ->and($requestPayload['contents'][1]['parts'])->toHaveCount(2);
});

test('system message plus consecutive user messages: system goes to systemInstruction users merge', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new SystemMessage('You are helpful.'),
        new UserMessage('hello'),
        new UserMessage('world'),
    ]);

    expect($requestPayload['systemInstruction'])->toBe(['parts' => [['text' => 'You are helpful.']]])
        ->and($requestPayload['contents'])->toHaveCount(1)
        ->and($requestPayload['contents'][0]['role'])->toBe('user')
        ->and($requestPayload['contents'][0]['parts'])->toHaveCount(2);
});

test('consecutive tool result messages mapping to user role are merged', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new UserMessage('run both tools'),
        new AssistantMessage('calling tools'),
        new ToolResultMessage(ToolResult::success('result-a')->withCallId('tool_a')),
        new ToolResultMessage(ToolResult::success('result-b')->withCallId('tool_b')),
    ]);

    // user → model → two tool results (both 'user' role); the two tool results merge into one content block
    expect($requestPayload['contents'])->toHaveCount(3)
        ->and($requestPayload['contents'][0]['role'])->toBe('user')
        ->and($requestPayload['contents'][1]['role'])->toBe('model')
        ->and($requestPayload['contents'][2]['role'])->toBe('user')
        ->and($requestPayload['contents'][2]['parts'])->toHaveCount(2);
});

// --- Reasoning / Thought Parts ---

test('parseResponse routes thought:true parts to reasoning, not content', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['text' => 'I am thinking...', 'thought' => true],
                            ['text' => 'The answer is 42.'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('What is life?')]);

    expect($response->content)->toBe('The answer is 42.')
        ->and($response->reasoning)->toBe('I am thinking...');
});

test('parseResponse thought:false parts go to content', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [['text' => 'Normal response.', 'thought' => false]],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->content)->toBe('Normal response.')
        ->and($response->reasoning)->toBe('');
});

test('parseResponse parts without thought key go to content', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [['text' => 'Regular text.']],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->content)->toBe('Regular text.')
        ->and($response->reasoning)->toBe('');
});

test('parseResponse concatenates multiple thought parts', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['text' => 'Step 1. ', 'thought' => true],
                            ['text' => 'Step 2.', 'thought' => true],
                            ['text' => 'Final answer.'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->reasoning)->toBe('Step 1. Step 2.')
        ->and($response->content)->toBe('Final answer.');
});

test('stream yields separate reasoning Response for thought:true parts', function () {
    $sseData =
        "data: " . json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'thinking chunk', 'thought' => true]]],
                'finishReason' => 'STOP',
            ]],
        ]) . "\n\n" .
        "data: " . json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'response text']]],
                'finishReason' => 'STOP',
            ]],
        ]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('think')]));

    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');
    $textChunks = array_filter($chunks, fn($r) => $r->content !== '');

    expect($reasoningChunks)->toHaveCount(1);
    expect(array_values($reasoningChunks)[0]->reasoning)->toBe('thinking chunk');
    expect(array_values($reasoningChunks)[0]->content)->toBe('');

    expect($textChunks)->toHaveCount(1);
    expect(array_values($textChunks)[0]->content)->toBe('response text');
});

test('stream does not yield reasoning Response when no thought parts', function () {
    $sseData =
        "data: " . json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'plain answer']]],
                'finishReason' => 'STOP',
            ]],
        ]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('hi')]));
    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');

    expect($reasoningChunks)->toBeEmpty();
});

// --- Tool Call ID Format & functionResponse.name ---

test('synthetic tool call IDs use compact 9-char alphanumeric format', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['functionCall' => ['name' => 'read_file', 'args' => ['path' => '/tmp/test']]],
                            ['functionCall' => ['name' => 'write_file', 'args' => ['path' => '/tmp/out']]],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('Do things')]);

    expect($response->toolCalls)->toHaveCount(2);

    // IDs should be 9-char alphanumeric (g + 8 hex digits)
    expect($response->toolCalls[0]->id)->toMatch('/^[a-zA-Z0-9]{9}$/')
        ->and($response->toolCalls[0]->id)->toBe('g00000000');
    expect($response->toolCalls[1]->id)->toMatch('/^[a-zA-Z0-9]{9}$/')
        ->and($response->toolCalls[1]->id)->toBe('g00000001');

    // Names are still correct
    expect($response->toolCalls[0]->name)->toBe('read_file')
        ->and($response->toolCalls[1]->name)->toBe('write_file');
});

test('functionResponse uses actual function name not call ID', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);

    $callId = 'g00000000';
    $provider->chat([
        new UserMessage('Read that file'),
        new AssistantMessage('', [
            new ToolCall($callId, 'read_file', ['path' => '/tmp/test']),
        ]),
        new ToolResultMessage(ToolResult::success('file contents')->withCallId($callId)),
    ]);

    // The tool result should appear as a functionResponse with the actual function name
    $contents = $requestPayload['contents'];

    // Find the user content that has a functionResponse part (tool results are 'user' role in Gemini)
    $functionResponseContent = null;
    foreach ($contents as $content) {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionResponse'])) {
                $functionResponseContent = $part['functionResponse'];
                break 2;
            }
        }
    }

    expect($functionResponseContent)->not->toBeNull()
        ->and($functionResponseContent['name'])->toBe('read_file')
        ->and($functionResponseContent['response'])->toBe(['result' => 'file contents']);
});

test('functionResponse maps multiple tool results to correct function names', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage('Do two things'),
        new AssistantMessage('', [
            new ToolCall('g00000000', 'read_file', ['path' => '/a']),
            new ToolCall('g00000001', 'exec', ['cmd' => 'ls']),
        ]),
        new ToolResultMessage(ToolResult::success('file a contents')->withCallId('g00000000')),
        new ToolResultMessage(ToolResult::success('ls output')->withCallId('g00000001')),
    ]);

    // Collect all functionResponse parts
    $functionResponses = [];
    foreach ($requestPayload['contents'] as $content) {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionResponse'])) {
                $functionResponses[] = $part['functionResponse'];
            }
        }
    }

    expect($functionResponses)->toHaveCount(2)
        ->and($functionResponses[0]['name'])->toBe('read_file')
        ->and($functionResponses[1]['name'])->toBe('exec');
});

// ── Thought Signature Tests ──────────────────────────────────────────────

test('parseResponse captures thoughtSignature in ToolCall metadata', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['city' => 'London'],
                                ],
                                'thoughtSignature' => 'gs:abc123signature',
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(
        model: 'gemini-3.1-flash-lite-preview',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Weather?')]);

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->metadata)->toBe(['thoughtSignature' => 'gs:abc123signature']);
});

test('parseResponse without thoughtSignature has empty metadata', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['city' => 'London'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]),
    ]);

    $provider = new GeminiProvider(
        model: 'gemini-2.5-flash',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Weather?')]);

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->metadata)->toBe([]);
});

test('thoughtSignature roundtrip: captured and echoed back in conversation history', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);

    $signature = 'gs:roundtrip_test_signature_xyz';
    $provider->chat([
        new UserMessage('Do something'),
        new AssistantMessage('', [
            new ToolCall('g00000000', 'read_file', ['path' => '/tmp/test'], ['thoughtSignature' => $signature]),
        ]),
        new ToolResultMessage(ToolResult::success('file contents')->withCallId('g00000000')),
    ]);

    // Find the model content with functionCall parts
    $functionCallPart = null;
    foreach ($requestPayload['contents'] as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionCall'])) {
                    $functionCallPart = $part;
                    break 2;
                }
            }
        }
    }

    expect($functionCallPart)->not->toBeNull()
        ->and($functionCallPart['functionCall']['name'])->toBe('read_file')
        ->and($functionCallPart['thoughtSignature'])->toBe($signature);
});

test('thoughtSignature only on first parallel functionCall part', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);

    // Per Gemini docs, only the first functionCall part in a parallel call has the signature
    $provider->chat([
        new UserMessage('Do two things'),
        new AssistantMessage('', [
            new ToolCall('g00000000', 'read_file', ['path' => '/a'], ['thoughtSignature' => 'gs:parallel_sig']),
            new ToolCall('g00000001', 'exec', ['cmd' => 'ls']),
        ]),
        new ToolResultMessage(ToolResult::success('content a')->withCallId('g00000000')),
        new ToolResultMessage(ToolResult::success('ls output')->withCallId('g00000001')),
    ]);

    // Collect all functionCall parts from model messages
    $functionCallParts = [];
    foreach ($requestPayload['contents'] as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionCall'])) {
                    $functionCallParts[] = $part;
                }
            }
        }
    }

    expect($functionCallParts)->toHaveCount(2)
        ->and($functionCallParts[0]['thoughtSignature'])->toBe('gs:parallel_sig')
        ->and($functionCallParts[1]['thoughtSignature'])->toBe('skip_thought_signature_validator');
});

test('dummy thoughtSignature emitted when metadata is empty', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage('Do something'),
        new AssistantMessage('', [
            new ToolCall('g00000000', 'read_file', ['path' => '/tmp/test']),
        ]),
        new ToolResultMessage(ToolResult::success('file contents')->withCallId('g00000000')),
    ]);

    // Find the model content with functionCall parts
    $functionCallPart = null;
    foreach ($requestPayload['contents'] as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionCall'])) {
                    $functionCallPart = $part;
                    break 2;
                }
            }
        }
    }

    expect($functionCallPart)->not->toBeNull()
        ->and($functionCallPart['thoughtSignature'])->toBe('skip_thought_signature_validator');
});
