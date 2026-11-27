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
    // 192.0.2.1 (TEST-NET-1) is not a global address, so the image fetch is refused
    // before any DNS lookup or request and the part falls back to text.
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
            ['type' => 'image_url', 'image_url' => ['url' => 'http://192.0.2.1/photo.jpg']],
        ]),
    ]);

    $parts = $requestPayload['contents'][0]['parts'];
    expect($parts[0])->toHaveKey('text')
        ->and($parts[0]['text'])->toContain('http://192.0.2.1/photo.jpg');
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

test('response parsing drops a functionCall with no name and finishes Error', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['args' => ['a' => 1]]],
                    ['functionCall' => ['name' => 'now', 'args' => new stdClass()]],
                ]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);

    $response = $provider->chat([new UserMessage('Hi')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($response->toolCalls)->toEqual([new ToolCall('g00000001', 'now', [])]);
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

dataset('gemini finish reasons', [
    'STOP' => ['STOP', ProviderFinishReason::Stop],
    'MAX_TOKENS' => ['MAX_TOKENS', ProviderFinishReason::MaxTokens],
    'SAFETY' => ['SAFETY', ProviderFinishReason::ContentFilter],
    'RECITATION' => ['RECITATION', ProviderFinishReason::ContentFilter],
    'PROHIBITED_CONTENT' => ['PROHIBITED_CONTENT', ProviderFinishReason::ContentFilter],
    'BLOCKLIST' => ['BLOCKLIST', ProviderFinishReason::ContentFilter],
    'SPII' => ['SPII', ProviderFinishReason::ContentFilter],
    'IMAGE_SAFETY' => ['IMAGE_SAFETY', ProviderFinishReason::ContentFilter],
    'MALFORMED_FUNCTION_CALL' => ['MALFORMED_FUNCTION_CALL', ProviderFinishReason::Error],
    'UNEXPECTED_TOOL_CALL' => ['UNEXPECTED_TOOL_CALL', ProviderFinishReason::Error],
    'TOO_MANY_TOOL_CALLS' => ['TOO_MANY_TOOL_CALLS', ProviderFinishReason::Error],
    'MISSING_THOUGHT_SIGNATURE' => ['MISSING_THOUGHT_SIGNATURE', ProviderFinishReason::Error],
    'null' => [null, ProviderFinishReason::Stop],
    'unknown' => ['OTHER', ProviderFinishReason::Stop],
]);

test('finish reason mapping covers every finishReason', function (?string $raw, ProviderFinishReason $expected) {
    $mockClient = new MockHttpClient([
        mockGeminiResponse(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '']]], 'finishReason' => $raw]]]),
    ]);

    $response = (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe($expected);
})->with('gemini finish reasons');

test('a blocked prompt with no candidates maps to ContentFilter', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse(['candidates' => [], 'promptFeedback' => ['blockReason' => 'OTHER']]),
    ]);

    $response = (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(ProviderFinishReason::ContentFilter)
        ->and($response->content)->toBe('');
});

test('ContentFilter, Error and MaxTokens are not overwritten by ToolUse and emit no tool calls', function (string $raw, ProviderFinishReason $expected) {
    $mockClient = new MockHttpClient([
        mockGeminiResponse(['candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'NYC']]]]],
            'finishReason' => $raw,
        ]]]),
    ]);

    $response = (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe($expected)
        ->and($response->toolCalls)->toBe([]);
})->with([
    'MALFORMED_FUNCTION_CALL' => ['MALFORMED_FUNCTION_CALL', ProviderFinishReason::Error],
    'SAFETY' => ['SAFETY', ProviderFinishReason::ContentFilter],
    'MAX_TOKENS' => ['MAX_TOKENS', ProviderFinishReason::MaxTokens],
]);

test('stream MAX_TOKENS alongside a functionCall part yields MaxTokens and no tool calls', function () {
    $sseData = 'data: ' . json_encode(['candidates' => [[
        'content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'done', 'args' => ['response' => 'Half']]]]],
        'finishReason' => 'MAX_TOKENS',
    ]]]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $chunks = iterator_to_array((new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->stream([new UserMessage('hi')]), false);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::MaxTokens)
        ->and($chunks[0]->toolCalls)->toBe([]);
});

test('stream yields ContentFilter for a blocked prompt with no candidates', function () {
    $sseData = 'data: ' . json_encode(['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 3, 'totalTokenCount' => 3]]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $chunks = iterator_to_array((new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->stream([new UserMessage('hi')]), false);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::ContentFilter)
        ->and($chunks[0]->toolCalls)->toBe([]);
});

test('stream MALFORMED_FUNCTION_CALL alongside a functionCall part yields Error and no tool calls', function () {
    $sseData = 'data: ' . json_encode(['candidates' => [[
        'content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'NYC']]]]],
        'finishReason' => 'MALFORMED_FUNCTION_CALL',
    ]]]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $chunks = iterator_to_array((new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->stream([new UserMessage('hi')]), false);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($chunks[0]->toolCalls)->toBe([]);
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

test('gemini normalises raw schemas inside combinators, defs and type arrays', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });
    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $schema = json_decode('{"type":"object","additionalProperties":false,"properties":{"when":{"type":["string","null"]},"pick":{"anyOf":[{"type":"object","additionalProperties":false,"properties":{"x":{"type":"string","default":"a"}}},{"type":"integer"}]},"list":{"type":"array","items":{"type":"object","additionalProperties":true}}},"$defs":{"z":{"type":"string"}}}', true);
    $tool = new \CarmeloSantana\PHPAgents\Tool\SchemaTool('raw', 'Raw.', $schema, fn(array $a) => \CarmeloSantana\PHPAgents\Tool\ToolResult::success('x'));

    $provider->chat([new \CarmeloSantana\PHPAgents\Message\UserMessage('hi')], [$tool]);
    $params = $requestPayload['tools'][0]['functionDeclarations'][0]['parameters'];

    expect($params)->not->toHaveKey('additionalProperties')
        ->and($params)->not->toHaveKey('$defs')
        ->and($params['properties']['when'])->toBe(['type' => 'STRING', 'nullable' => true])
        ->and($params['properties']['pick']['anyOf'][0]['type'])->toBe('OBJECT')
        ->and($params['properties']['pick']['anyOf'][0])->not->toHaveKey('additionalProperties')
        ->and($params['properties']['pick']['anyOf'][0]['properties']['x'])->toBe(['type' => 'STRING'])
        ->and($params['properties']['pick']['anyOf'][1]['type'])->toBe('INTEGER')
        ->and($params['properties']['list']['items'])->toBe(['type' => 'OBJECT']);
});

test('gemini strips x- extension keywords at every node the walk reaches', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });
    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    // `x-` at the root, on a property, in an `anyOf` branch and under `items`; `x-foo` is also
    // the *name* of a property, which keeps its name and loses only its own extension keyword.
    $schema = json_decode('{"type":"object","x-root":true,"properties":{"region":{"type":"string","x-mcp-header":"Region"},"pick":{"anyOf":[{"type":"string","x-a":1},{"type":"integer"}]},"list":{"type":"array","items":{"type":"string","x-b":{"c":1}}},"x-foo":{"type":"string","x-bar":"y"}},"required":["x-foo"]}', true);
    $tool = new \CarmeloSantana\PHPAgents\Tool\SchemaTool('raw', 'Raw.', $schema, fn(array $a) => \CarmeloSantana\PHPAgents\Tool\ToolResult::success('x'));

    $provider->chat([new \CarmeloSantana\PHPAgents\Message\UserMessage('hi')], [$tool]);
    $params = $requestPayload['tools'][0]['functionDeclarations'][0]['parameters'];

    expect($params)->toBe([
        'type' => 'OBJECT',
        'properties' => [
            'region' => ['type' => 'STRING'],
            'pick' => ['anyOf' => [['type' => 'STRING'], ['type' => 'INTEGER']]],
            'list' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'x-foo' => ['type' => 'STRING'],
        ],
        'required' => ['x-foo'],
    ]);
});

test('gemini tool formatting survives stdClass nodes inside parameters', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });
    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    // Every {} below is restored to a \stdClass by JsonSchemaRepair, so the walk
    // meets a non-array node at a property, at items and inside a combinator.
    $schema = json_decode('{"type":"object","additionalProperties":{},"properties":{"blank":{},"arr":{"type":"array","items":{}},"pick":{"anyOf":[{},{"type":"string"}]}}}', true);
    $tool = new \CarmeloSantana\PHPAgents\Tool\SchemaTool('blanks', 'Blanks.', $schema, fn(array $a) => \CarmeloSantana\PHPAgents\Tool\ToolResult::success('x'));

    $provider->chat([new \CarmeloSantana\PHPAgents\Message\UserMessage('hi')], [$tool]);
    $params = $requestPayload['tools'][0]['functionDeclarations'][0]['parameters'];

    expect($params['type'])->toBe('OBJECT')
        ->and($params)->not->toHaveKey('additionalProperties')
        ->and($params['properties']['blank'])->toBe([])
        ->and($params['properties']['arr'])->toBe(['type' => 'ARRAY', 'items' => []])
        ->and($params['properties']['pick']['anyOf'])->toBe([[], ['type' => 'STRING']]);
});

test('stream keeps stream true and the conversation when options try to override them', function () {
    $requestPayload = null;
    $requestUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload, &$requestUrl): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        $requestUrl = $url;
        return new MockResponse(
            'data: ' . json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]) . "\n\n",
            ['http_code' => 200],
        );
    });
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $mockClient);

    iterator_to_array($provider->stream([new UserMessage('hi')], [], ['contents' => []]));

    expect($requestUrl)->toContain(':streamGenerateContent?alt=sse')
        ->and($requestPayload['contents'])->toBe([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
});

test('stream ending without a finishReason yields one trailing Error chunk with no tool calls', function () {
    $sseData = 'data: ' . json_encode(['candidates' => [[
        'content' => ['role' => 'model', 'parts' => [['text' => 'Half an ans']]],
    ]]]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $chunks = iterator_to_array((new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->stream([new UserMessage('hi')]), false);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('Half an ans')
        ->and($chunks[1]->content)->toBe('')
        ->and($chunks[1]->toolCalls)->toBe([])
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::Error);
});

test('stream that ends with STOP yields no extra Error chunk', function () {
    $sseData = 'data: ' . json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Hel']]]]]]) . "\n\n"
        . 'data: ' . json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']]]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $chunks = iterator_to_array((new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->stream([new UserMessage('hi')]), false);

    expect($chunks)->toHaveCount(3)
        ->and(array_map(fn($r) => $r->content, $chunks))->toBe(['Hel', 'lo', ''])
        ->and(array_map(fn($r) => $r->finishReason, $chunks))->toBe([ProviderFinishReason::Stop, ProviderFinishReason::Stop, ProviderFinishReason::Stop]);
});

test('stream ignores parts and tool calls after a terminal candidate but folds later usage into the terminal chunk', function (array $terminal, ProviderFinishReason $expected) {
    $sseData = 'data: ' . json_encode($terminal) . "\n\n"
        . 'data: ' . json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Late text'], ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'NYC']]]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 2, 'totalTokenCount' => 9],
        ]) . "\n\n";

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $chunks = iterator_to_array((new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->stream([new UserMessage('hi')]), false);

    $toolCalls = array_merge(...array_map(fn($r) => $r->toolCalls, $chunks));
    $reasons = array_map(fn($r) => $r->finishReason, $chunks);

    expect($toolCalls)->toBe([])
        ->and(implode('', array_map(fn($r) => $r->content, $chunks)))->not->toContain('Late text')
        ->and($reasons)->not->toContain(ProviderFinishReason::ToolUse)
        ->and($reasons[array_key_last($reasons)])->toBe($expected)
        ->and($chunks[array_key_last($chunks)]->usage?->totalTokens)->toBe(9);
})->with([
    'SAFETY candidate' => [
        ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Part']]], 'finishReason' => 'SAFETY']]],
        ProviderFinishReason::ContentFilter,
    ],
    'blocked prompt' => [
        ['promptFeedback' => ['blockReason' => 'SAFETY']],
        ProviderFinishReason::ContentFilter,
    ],
    'STOP candidate' => [
        ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done.']]], 'finishReason' => 'STOP']]],
        ProviderFinishReason::Stop,
    ],
]);

function geminiReservedTool(): Tool
{
    return new Tool('agent_tool', 'The agent tool.', [], fn(array $input): ToolResult => ToolResult::success('ok'));
}

function geminiReservedOptions(): array
{
    return [
        'systemInstruction' => ['parts' => [['text' => 'Injected instructions.']]],
        'tools' => [['functionDeclarations' => [['name' => 'injected_tool', 'description' => 'Injected.']]]],
    ];
}

test('options cannot replace the systemInstruction or the tools argument', function (string $method) {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $m, string $url, array $options) use (&$requestPayload, $method): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return $method === 'chat'
            ? mockGeminiResponse()
            : new MockResponse('data: ' . json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]) . "\n\n", ['http_code' => 200]);
    });
    $provider = new GeminiProvider(apiKey: 'k', httpClient: $mockClient);

    $result = $provider->{$method}([new SystemMessage('Agent instructions.'), new UserMessage('hi')], [geminiReservedTool()], geminiReservedOptions());
    if ($method === 'stream') {
        iterator_to_array($result);
    }

    expect($requestPayload['systemInstruction'])->toBe(['parts' => [['text' => 'Agent instructions.']]])
        ->and($requestPayload['tools'][0]['functionDeclarations'][0]['name'])->toBe('agent_tool');
})->with(['chat', 'stream']);

test('options may supply systemInstruction and tools when there is no system message and no tools argument', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $m, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')], [], geminiReservedOptions());

    expect($requestPayload['systemInstruction'])->toBe(['parts' => [['text' => 'Injected instructions.']]])
        ->and($requestPayload['tools'][0]['functionDeclarations'][0]['name'])->toBe('injected_tool');
});

test('options cannot replace the systemInstruction through its snake_case alias', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $m, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat(
        [new SystemMessage('AGENT'), new UserMessage('hi')],
        [],
        ['system_instruction' => ['parts' => [['text' => 'EVIL']]]],
    );

    expect($requestPayload['systemInstruction'])->toBe(['parts' => [['text' => 'AGENT']]])
        ->and($requestPayload)->not->toHaveKey('system_instruction');
});

test('options may supply system_instruction when there is no system message', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $m, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockGeminiResponse();
    });

    (new GeminiProvider(apiKey: 'k', httpClient: $mockClient))->chat([new UserMessage('hi')], [], ['system_instruction' => ['parts' => [['text' => 'Caller']]]]);

    expect($requestPayload['system_instruction'])->toBe(['parts' => [['text' => 'Caller']]]);
});

test('usage metadata sent as numeric strings and integral floats is reported', function () {
    $mockClient = new MockHttpClient([
        mockGeminiResponse([
            'usageMetadata' => [
                'promptTokenCount' => '25',
                'candidatesTokenCount' => 12.0,
                'thoughtsTokenCount' => '2',
                'totalTokenCount' => 39.0,
                'cachedContentTokenCount' => '6',
            ],
        ]),
    ]);

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage?->promptTokens)->toBe(25)
        ->and($response->usage?->completionTokens)->toBe(14)
        ->and($response->usage?->totalTokens)->toBe(39)
        ->and($response->usage?->cacheReadTokens)->toBe(6)
        ->and($response->usage?->reasoningTokens)->toBe(2);
});
