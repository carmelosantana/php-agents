<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function mockOpenAIResponse(array $overrides = []): MockResponse
{
    $body = json_encode(array_merge([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => 'gpt-4o',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], $overrides));

    return new MockResponse($body, ['http_code' => 200]);
}

test('chat returns correct response structure', function () {
    $mockClient = new MockHttpClient([mockOpenAIResponse()]);

    $provider = new OpenAICompatibleProvider(
        model: 'gpt-4o',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Hi')]);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->content)->toBe('Hello!')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->toolCalls)->toBeEmpty()
        ->and($response->model)->toBe('gpt-4o');
});

test('chat payload includes model, messages, stream=false', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $provider->chat([new UserMessage('Hello')]);

    expect($requestPayload['model'])->toBe('gpt-4o')
        ->and($requestPayload['stream'])->toBeFalse()
        ->and($requestPayload['messages'])->toHaveCount(1)
        ->and($requestPayload['messages'][0]['role'])->toBe('user')
        ->and($requestPayload['messages'][0]['content'])->toBe('Hello');
});

test('tools are included in payload when provided', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);

    $tool = new Tool(
        name: 'get_weather',
        description: 'Get weather',
        parameters: [new StringParameter('city', 'City name', required: true)],
        callback: fn(array $args): ToolResult => ToolResult::success('sunny'),
    );

    $provider->chat([new UserMessage('Weather?')], [$tool]);

    expect($requestPayload)->toHaveKey('tools')
        ->and($requestPayload['tools'])->toHaveCount(1)
        ->and($requestPayload['tools'][0]['function']['name'])->toBe('get_weather');
});

test('zero parameter tools include required and additionalProperties fields', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);

    $tool = new Tool(
        name: 'ping',
        description: 'Health check',
        parameters: [],
        callback: fn(array $args): ToolResult => ToolResult::success('pong'),
    );

    $provider->chat([new UserMessage('Ping')], [$tool]);

    expect($requestPayload['tools'][0]['function']['parameters'])
        ->toMatchArray([
            'type' => 'object',
            'required' => [],
            'additionalProperties' => false,
        ])
        ->and($requestPayload['tools'][0]['function']['parameters']['properties'])->toBe([]);
});

test('chat trims oversized tool payloads to OpenAI limit and logs warning', function () {
    $requestPayload = null;
    $records = new ArrayObject();
    $logger = new class ($records) extends AbstractLogger {
        public function __construct(private readonly ArrayObject $records) {}

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->records[] = [
                'level' => $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };

    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient, logger: $logger);

    $tools = [];
    for ($i = 0; $i < 135; $i++) {
        $tools[] = new Tool(
            name: sprintf('tool_%03d', $i),
            description: 'Test tool',
            parameters: [],
            callback: fn(array $args): ToolResult => ToolResult::success('ok'),
        );
    }

    $provider->chat([new UserMessage('Use tools')], $tools);

    expect($requestPayload['tools'])->toHaveCount(128)
        ->and($requestPayload['tools'][0]['function']['name'])->toBe('tool_000')
        ->and($requestPayload['tools'][127]['function']['name'])->toBe('tool_127')
        ->and($records)->toHaveCount(1)
        ->and($records[0]['level'])->toBe('warning')
        ->and($records[0]['context']['tool_count'])->toBe(135)
        ->and($records[0]['context']['tool_limit'])->toBe(128);
});

test('stream trims oversized tool payloads to OpenAI limit', function () {
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

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);

    $tools = [];
    for ($i = 0; $i < 135; $i++) {
        $tools[] = new Tool(
            name: sprintf('tool_%03d', $i),
            description: 'Test tool',
            parameters: [],
            callback: fn(array $args): ToolResult => ToolResult::success('ok'),
        );
    }

    iterator_to_array($provider->stream([new UserMessage('Use tools')], $tools));

    expect($requestPayload['tools'])->toHaveCount(128)
        ->and($requestPayload['tools'][127]['function']['name'])->toBe('tool_127');
});

test('tools key is omitted when no tools', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload)->not->toHaveKey('tools');
});

test('response tool calls are parsed correctly', function () {
    $mockClient = new MockHttpClient([
        mockOpenAIResponse([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_abc',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"city":"NYC"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('Weather?')]);

    expect($response->finishReason)->toBe(FinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->id)->toBe('call_abc')
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[0]->arguments)->toBe(['city' => 'NYC']);
});

test('multiple tool calls are all parsed', function () {
    $mockClient = new MockHttpClient([
        mockOpenAIResponse([
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'tool_a', 'arguments' => '{}']],
                            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'tool_b', 'arguments' => '{"x":1}']],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('do stuff')]);

    expect($response->toolCalls)->toHaveCount(2)
        ->and($response->toolCalls[0]->name)->toBe('tool_a')
        ->and($response->toolCalls[1]->name)->toBe('tool_b');
});

test('usage is parsed from response', function () {
    $mockClient = new MockHttpClient([
        mockOpenAIResponse([
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
        ]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage)->not->toBeNull()
        ->and($response->usage->promptTokens)->toBe(20)
        ->and($response->usage->completionTokens)->toBe(10)
        ->and($response->usage->totalTokens)->toBe(30);
});

test('null usage when usage data missing', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]), ['http_code' => 200]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage)->toBeNull();
});

test('finish reason mapping: stop', function () {
    $mockClient = new MockHttpClient([mockOpenAIResponse(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]])]);
    $response = (new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(FinishReason::Stop);
});

test('finish reason mapping: length', function () {
    $mockClient = new MockHttpClient([mockOpenAIResponse(['choices' => [['message' => ['content' => 'truncat'], 'finish_reason' => 'length']]])]);
    $response = (new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(FinishReason::MaxTokens);
});

test('finish reason mapping: tool_calls', function () {
    $mockClient = new MockHttpClient([mockOpenAIResponse(['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => '1', 'type' => 'function', 'function' => ['name' => 't', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]])]);
    $response = (new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(FinishReason::ToolUse);
});

test('finish reason mapping: function_call', function () {
    $mockClient = new MockHttpClient([mockOpenAIResponse(['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => '1', 'type' => 'function', 'function' => ['name' => 't', 'arguments' => '{}']]]], 'finish_reason' => 'function_call']]])]);
    $response = (new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient))->chat([new UserMessage('hi')]);
    expect($response->finishReason)->toBe(FinishReason::ToolUse);
});

test('headers include Bearer auth', function () {
    $capturedHeaders = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
        $capturedHeaders = $options['normalized_headers'] ?? $options['headers'] ?? [];
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'sk-test-key', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($capturedHeaders)->toBeArray();
});

test('system and assistant messages format correctly', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $provider->chat([
        new SystemMessage('You are helpful.'),
        new UserMessage('Hi'),
        new AssistantMessage('Hello!'),
        new UserMessage('Thanks'),
    ]);

    expect($requestPayload['messages'])->toHaveCount(4)
        ->and($requestPayload['messages'][0]['role'])->toBe('system')
        ->and($requestPayload['messages'][1]['role'])->toBe('user')
        ->and($requestPayload['messages'][2]['role'])->toBe('assistant')
        ->and($requestPayload['messages'][3]['role'])->toBe('user');
});

test('URL targets chat completions endpoint', function () {
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', baseUrl: 'https://api.openai.com/v1', apiKey: 'key', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($capturedUrl)->toBe('https://api.openai.com/v1/chat/completions');
});

test('structured output includes response_format', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $schema = json_encode(['name' => 'test', 'schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]]);
    $provider->structured([new UserMessage('give me json')], $schema);

    expect($requestPayload['response_format']['type'])->toBe('json_schema')
        ->and($requestPayload['response_format']['json_schema']['name'])->toBe('test');
});

test('options are spread into chat payload', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')], [], ['temperature' => 0.7, 'max_tokens' => 100]);

    expect($requestPayload['temperature'])->toBe(0.7)
        ->and($requestPayload['max_tokens'])->toBe(100);
});

test('empty content defaults to empty string', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'choices' => [['message' => ['role' => 'assistant'], 'finish_reason' => 'stop']],
        ]), ['http_code' => 200]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->content)->toBe('');
});

test('stream produces text deltas', function () {
    $sseData = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'He'], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'llo'], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]),
        'data: ' . json_encode(['usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7]]),
        'data: [DONE]',
        '',
    ]);

    $mockClient = new MockHttpClient([
        new MockResponse($sseData, ['http_code' => 200]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);

    $chunks = [];
    foreach ($provider->stream([new UserMessage('hi')]) as $chunk) {
        $chunks[] = $chunk;
    }

    // Text chunk 1, Text chunk 2, Stop chunk, Usage chunk
    expect($chunks)->toHaveCount(4)
        ->and($chunks[0]->content)->toBe('He')
        ->and($chunks[1]->content)->toBe('llo')
        ->and($chunks[2]->finishReason)->toBe(FinishReason::Stop)
        ->and($chunks[3]->usage)->not->toBeNull()
        ->and($chunks[3]->usage->totalTokens)->toBe(7);
});

test('stream assembles tool call deltas', function () {
    $sseData = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '{"ci']]]], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ty":"NY']]]], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'C"}']]]], 'finish_reason' => 'tool_calls']]]),
        'data: [DONE]',
        '',
    ]);

    $mockClient = new MockHttpClient([
        new MockResponse($sseData, ['http_code' => 200]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);

    $chunks = [];
    foreach ($provider->stream([new UserMessage('weather')]) as $chunk) {
        $chunks[] = $chunk;
    }

    // Should get exactly one tool call response
    $toolChunk = array_filter($chunks, fn(Response $r) => $r->finishReason === FinishReason::ToolUse);
    expect($toolChunk)->toHaveCount(1);

    $tc = array_values($toolChunk)[0];
    expect($tc->toolCalls)->toHaveCount(1)
        ->and($tc->toolCalls[0]->id)->toBe('call_1')
        ->and($tc->toolCalls[0]->name)->toBe('get_weather')
        ->and($tc->toolCalls[0]->arguments)->toBe(['city' => 'NYC']);
});

test('image content blocks pass through in formatMessages', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOpenAIResponse();
    });

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Describe this'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,abc']],
        ]),
    ]);

    $content = $requestPayload['messages'][0]['content'];
    expect($content)->toHaveCount(2)
        ->and($content[0]['type'])->toBe('text')
        ->and($content[1]['type'])->toBe('image_url')
        ->and($content[1]['image_url']['url'])->toBe('data:image/png;base64,abc');
});

// --- Reasoning / Thinking (Ollama non-streaming) ---

test('parseResponse extracts thinking field from Ollama non-streaming response', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'The answer is 42.',
                    'thinking' => 'Let me reason through this step by step.',
                ],
                'finish_reason' => 'stop',
            ]],
            'model' => 'qwen3:latest',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ]), ['http_code' => 200]),
    ]);

    $provider = new OpenAICompatibleProvider(model: 'qwen3:latest', apiKey: '', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('What is the answer?')]);

    expect($response->content)->toBe('The answer is 42.')
        ->and($response->reasoning)->toBe('Let me reason through this step by step.');
});

test('parseResponse reasoning is empty string when no thinking field', function () {
    $mockClient = new MockHttpClient([mockOpenAIResponse()]);

    $provider = new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->reasoning)->toBe('');
});

test('stream yields reasoning Response chunks from delta.reasoning field', function () {
    $sseData = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['reasoning' => 'thinking... '], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => ['reasoning' => 'more thinking'], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Final answer.'], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]),
        'data: [DONE]',
        '',
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new OpenAICompatibleProvider(model: 'qwen3:latest', apiKey: '', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('think')]));

    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');
    // Each delta yields one reasoning chunk; the stop event does NOT re-broadcast the buffer
    expect($reasoningChunks)->toHaveCount(2);

    $deltaReasonings = array_map(fn($r) => $r->reasoning, array_values($reasoningChunks));
    expect($deltaReasonings)->toBe(['thinking... ', 'more thinking']);
});

test('stream yields reasoning Response chunks from delta.thinking field', function () {
    $sseData = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['thinking' => 'deep thought'], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Answer.'], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]),
        'data: [DONE]',
        '',
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new OpenAICompatibleProvider(model: 'ollama-think', apiKey: '', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('think')]));

    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');
    // Exactly 1 delta chunk; the stop event does NOT re-broadcast the buffer
    expect($reasoningChunks)->toHaveCount(1);
    expect(array_values($reasoningChunks)[0]->reasoning)->toBe('deep thought');
});

test('stream reasoning chunks have empty content', function () {
    $sseData = implode("\n", [
        'data: ' . json_encode(['choices' => [['delta' => ['reasoning' => 'reasoning token'], 'finish_reason' => null]]]),
        'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]),
        'data: [DONE]',
        '',
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new OpenAICompatibleProvider(model: 'qwen3', apiKey: '', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('hi')]));
    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');

    foreach ($reasoningChunks as $chunk) {
        expect($chunk->content)->toBe('');
    }
});
