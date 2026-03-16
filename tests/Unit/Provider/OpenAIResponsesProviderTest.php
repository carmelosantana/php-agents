<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Build a mock Responses API response body.
 *
 * @param array<string, mixed> $overrides
 */
function mockResponsesApiResponse(array $overrides = []): MockResponse
{
    $body = json_encode(array_merge([
        'id' => 'resp_test',
        'object' => 'response',
        'model' => 'gpt-4o',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'message',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Hello from Responses API!'],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ], $overrides));

    return new MockResponse($body, ['http_code' => 200]);
}

// --- Basic chat ---

test('chat returns correct response structure', function () {
    $mockClient = new MockHttpClient([mockResponsesApiResponse()]);

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('Hi')]);

    expect($response->content)->toBe('Hello from Responses API!')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->toolCalls)->toBeEmpty()
        ->and($response->model)->toBe('gpt-4o');
});

test('chat sends request to /responses endpoint', function () {
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;
        return mockResponsesApiResponse();
    });

    $provider = new OpenAIResponsesProvider(
        model: 'gpt-4o',
        baseUrl: 'https://api.openai.com/v1',
        apiKey: 'test-key',
        httpClient: $mockClient,
    );

    $provider->chat([new UserMessage('hi')]);

    expect($capturedUrl)->toBe('https://api.openai.com/v1/responses');
});

test('chat payload uses input array instead of messages', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockResponsesApiResponse();
    });

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new SystemMessage('You are helpful.'),
        new UserMessage('Hello'),
    ]);

    expect($requestPayload)->toHaveKey('input')
        ->and($requestPayload)->not->toHaveKey('messages')
        ->and($requestPayload['input'])->toBeArray();
});

test('chat formats system message with role:system', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockResponsesApiResponse();
    });

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $provider->chat([
        new SystemMessage('System instruction.'),
        new UserMessage('User question.'),
    ]);

    $systemMsg = array_filter($requestPayload['input'], fn($m) => ($m['role'] ?? '') === 'system');
    expect($systemMsg)->toHaveCount(1);
    expect(array_values($systemMsg)[0]['content'])->toBe('System instruction.');
});

test('chat parses usage using input_tokens and output_tokens', function () {
    $mockClient = new MockHttpClient([
        mockResponsesApiResponse([
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
        ]),
    ]);

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage)->not->toBeNull()
        ->and($response->usage->promptTokens)->toBe(20)
        ->and($response->usage->completionTokens)->toBe(10)
        ->and($response->usage->totalTokens)->toBe(30);
});

test('chat parses function_call output items as tool calls', function () {
    $mockClient = new MockHttpClient([
        mockResponsesApiResponse([
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'function_call',
                    'call_id' => 'call_abc123',
                    'name' => 'get_weather',
                    'arguments' => '{"city":"NYC"}',
                ],
            ],
        ]),
    ]);

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('Weather?')]);

    expect($response->finishReason)->toBe(FinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->id)->toBe('call_abc123')
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[0]->arguments)->toBe(['city' => 'NYC']);
});

test('chat incomplete status maps to MaxTokens finish reason', function () {
    $mockClient = new MockHttpClient([
        mockResponsesApiResponse(['status' => 'incomplete']),
    ]);

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->finishReason)->toBe(FinishReason::MaxTokens);
});

// --- Reasoning ---

test('parseResponse extracts reasoning output items into reasoning field', function () {
    $mockClient = new MockHttpClient([
        mockResponsesApiResponse([
            'output' => [
                [
                    'type' => 'reasoning',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'I am reasoning through this problem.'],
                    ],
                ],
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'The answer.'],
                    ],
                ],
            ],
        ]),
    ]);

    $provider = new OpenAIResponsesProvider(model: 'o1', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('Think carefully')]);

    expect($response->content)->toBe('The answer.')
        ->and($response->reasoning)->toBe('I am reasoning through this problem.');
});

test('parseResponse concatenates multiple reasoning summary blocks', function () {
    $mockClient = new MockHttpClient([
        mockResponsesApiResponse([
            'output' => [
                [
                    'type' => 'reasoning',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'Step one. '],
                        ['type' => 'summary_text', 'text' => 'Step two.'],
                    ],
                ],
                [
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Done.']],
                ],
            ],
        ]),
    ]);

    $provider = new OpenAIResponsesProvider(model: 'o3', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->reasoning)->toBe('Step one. Step two.');
});

test('parseResponse reasoning is empty when no reasoning output item', function () {
    $mockClient = new MockHttpClient([mockResponsesApiResponse()]);

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->reasoning)->toBe('');
});

// --- Streaming ---

test('stream yields text delta chunks from response.output_text.delta events', function () {
    $sseData = implode('', [
        "data: " . json_encode(['type' => 'response.created', 'response' => ['model' => 'gpt-4o']]) . "\n\n",
        "data: " . json_encode(['type' => 'response.output_text.delta', 'delta' => 'Hello ']) . "\n\n",
        "data: " . json_encode(['type' => 'response.output_text.delta', 'delta' => 'world!']) . "\n\n",
        "data: " . json_encode(['type' => 'response.completed', 'response' => ['model' => 'gpt-4o', 'status' => 'completed', 'usage' => ['input_tokens' => 5, 'output_tokens' => 3]]]) . "\n\n",
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('hi')]));

    $textChunks = array_filter($chunks, fn($r) => $r->content !== '');
    expect($textChunks)->toHaveCount(2);

    $allText = implode('', array_map(fn($r) => $r->content, $textChunks));
    expect($allText)->toBe('Hello world!');
});

test('stream yields reasoning chunks from response.reasoning_summary_text.delta events', function () {
    $sseData = implode('', [
        "data: " . json_encode(['type' => 'response.reasoning_summary_text.delta', 'delta' => 'I think ']) . "\n\n",
        "data: " . json_encode(['type' => 'response.reasoning_summary_text.delta', 'delta' => 'carefully.']) . "\n\n",
        "data: " . json_encode(['type' => 'response.output_text.delta', 'delta' => 'Answer.']) . "\n\n",
        "data: " . json_encode(['type' => 'response.completed', 'response' => ['model' => 'o3', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]]) . "\n\n",
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'o3', apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('think')]));

    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');
    expect($reasoningChunks)->toHaveCount(2);

    $allReasoning = implode('', array_map(fn($r) => $r->reasoning, $reasoningChunks));
    expect($allReasoning)->toBe('I think carefully.');
});

test('stream reasoning chunks have empty content', function () {
    $sseData = implode('', [
        "data: " . json_encode(['type' => 'response.reasoning_summary_text.delta', 'delta' => 'reasoning token']) . "\n\n",
        "data: " . json_encode(['type' => 'response.completed', 'response' => ['model' => 'o3', 'usage' => ['input_tokens' => 5, 'output_tokens' => 2]]]) . "\n\n",
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'o3', apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('hi')]));
    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');

    foreach ($reasoningChunks as $chunk) {
        expect($chunk->content)->toBe('');
    }
});

test('stream yields tool call on response.completed when pending tool calls exist', function () {
    $sseData = implode('', [
        "data: " . json_encode(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_weather']]) . "\n\n",
        "data: " . json_encode(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"city"']) . "\n\n",
        "data: " . json_encode(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => ':"NYC"}']) . "\n\n",
        "data: " . json_encode(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":"NYC"}']]) . "\n\n",
        "data: " . json_encode(['type' => 'response.completed', 'response' => ['model' => 'gpt-4o', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]]) . "\n\n",
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('weather?')]));

    $toolChunks = array_filter($chunks, fn($r) => $r->finishReason === FinishReason::ToolUse);
    expect($toolChunks)->toHaveCount(1);

    $tc = array_values($toolChunks)[0];
    expect($tc->toolCalls)->toHaveCount(1)
        ->and($tc->toolCalls[0]->id)->toBe('call_1')
        ->and($tc->toolCalls[0]->name)->toBe('get_weather')
        ->and($tc->toolCalls[0]->arguments)->toBe(['city' => 'NYC']);
});

// --- Structured output ---

test('structured output uses function tool trick and returns arguments', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return new MockResponse(json_encode([
            'id' => 'resp_test',
            'status' => 'completed',
            'model' => 'gpt-4o',
            'output' => [
                [
                    'type' => 'function_call',
                    'call_id' => 'call_1',
                    'name' => 'extract',
                    'arguments' => '{"name":"Alice","age":30}',
                ],
            ],
        ]), ['http_code' => 200]);
    });

    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $mockClient);
    $schema = json_encode([
        'name' => 'extract',
        'description' => 'Extract person data',
        'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']]],
    ]);

    $result = $provider->structured([new UserMessage('Extract: Alice, 30 years old')], $schema);

    expect($requestPayload['tools'])->toHaveCount(1)
        ->and($requestPayload['tools'][0]['name'])->toBe('extract')
        ->and($requestPayload['tool_choice'])->toBe(['type' => 'function', 'name' => 'extract'])
        ->and($result)->toBe(['name' => 'Alice', 'age' => 30]);
});
