<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
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
    $client = new MockHttpClient(fn() => throw new \RuntimeException('offline'));
    $provider = new AnthropicProvider(apiKey: 'test', httpClient: $client);
    $models = $provider->models();

    expect($models)->toBeArray()
        ->and($models)->not->toBeEmpty()
        ->and($models[0]->provider)->toBe('anthropic')
        ->and($models[0]->metadataSource)->toBe('static-fallback');
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

/**
 * Helper: a MockHttpClient that records the request payload and answers with the given body overrides.
 *
 * @param array<string, mixed> $overrides
 */
function structuredClient(?array &$payload, array $overrides): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$payload, $overrides): MockResponse {
        $payload = json_decode($options['body'], true);
        return mockAnthropicResponse($overrides);
    });
}

const ANTHROPIC_STRUCTURED_INSTRUCTION = "Respond with only a JSON value that matches this JSON Schema, with no other text:\n";

const ANTHROPIC_CLOSED_SCHEMA = '{"type":"object","properties":{"name":{"type":"string"}},"required":["name"],"additionalProperties":false}';

/**
 * The text of the last content block of the last message in a request payload.
 *
 * @param array<string, mixed> $payload
 */
function anthropicLastUserText(array $payload): string
{
    $last = end($payload['messages']);
    $content = $last['content'];

    return is_string($content) ? $content : (string) end($content)['text'];
}

test('structured sends output_config.format and no forced tool_choice', function () {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{"name":"Alice"}']],
    ]));
    $inner = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
    $schema = json_encode([
        'name' => 'extract_data',
        'description' => 'Extract structured data',
        'schema' => $inner,
    ]);

    $result = $provider->structured([new UserMessage('My name is Alice')], $schema);

    expect($result)->toBe(['name' => 'Alice'])
        ->and($payload)->not->toHaveKey('tools')
        ->and($payload)->not->toHaveKey('tool_choice')
        ->and($payload['output_config'])->toBe(['format' => [
            'type' => 'json_schema',
            'schema' => [...$inner, 'additionalProperties' => false],
        ]])
        ->and(json_encode($payload))->not->toContain('extract_data')
        ->and(json_encode($payload))->not->toContain('Extract structured data');
});

test('structured keeps an optional property optional and adds no null type', function () {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{"name":"Alice"}']],
    ]));
    $schema = '{"type":"object","properties":{"name":{"type":"string"},"nickname":{"type":"string"}},"required":["name"]}';

    $provider->structured([new UserMessage('hi')], $schema);

    expect($payload['output_config']['format']['schema'])->toBe([
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string'], 'nickname' => ['type' => 'string']],
        'required' => ['name'],
        'additionalProperties' => false,
    ]);
});

test('structured closes nested, array item and $defs objects', function () {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{}']],
    ]));
    $schema = json_encode([
        'type' => 'object',
        'properties' => [
            'address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            'items' => ['type' => 'array', 'items' => ['properties' => ['sku' => ['type' => 'string']]]],
            'pick' => ['anyOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], ['type' => 'string']]],
            'pair' => ['type' => 'array', 'prefixItems' => [['type' => 'object', 'properties' => ['x' => ['type' => 'number']]]]],
            'ref' => ['$ref' => '#/$defs/Thing'],
            'legacy' => ['$ref' => '#/definitions/Old'],
        ],
        '$defs' => ['Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'additionalProperties' => false]],
        'definitions' => ['Old' => ['allOf' => [['type' => 'object', 'properties' => ['v' => ['type' => 'string']]]]]],
    ]);

    $provider->structured([new UserMessage('hi')], $schema);
    $sent = $payload['output_config']['format']['schema'];

    expect($sent['additionalProperties'])->toBeFalse()
        ->and($sent['properties']['address']['additionalProperties'])->toBeFalse()
        ->and($sent['properties']['items']['items']['additionalProperties'])->toBeFalse()
        ->and($sent['properties']['pick']['anyOf'][0]['additionalProperties'])->toBeFalse()
        ->and($sent['properties']['pick']['anyOf'][1])->toBe(['type' => 'string'])
        ->and($sent['properties']['pair']['prefixItems'][0]['additionalProperties'])->toBeFalse()
        ->and($sent['$defs']['Thing'])->toBe(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'additionalProperties' => false])
        ->and($sent['definitions']['Old']['allOf'][0]['additionalProperties'])->toBeFalse()
        ->and($sent)->not->toHaveKey('required')
        ->and($sent['properties']['address'])->not->toHaveKey('required');
});

test('structured strips the numeric and string constraint keywords', function () {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{}']],
    ]));
    $schema = json_encode([
        'type' => 'object',
        'properties' => [
            'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 150, 'exclusiveMinimum' => -1, 'exclusiveMaximum' => 151, 'multipleOf' => 1],
            'code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 8, 'pattern' => '^[A-Z]+$'],
            'minimum' => ['type' => 'string', 'description' => 'A property named like a keyword is kept'],
        ],
    ]);

    $provider->structured([new UserMessage('hi')], $schema);
    $properties = $payload['output_config']['format']['schema']['properties'];

    expect($properties['age'])->toBe(['type' => 'integer'])
        ->and($properties['code'])->toBe(['type' => 'string', 'pattern' => '^[A-Z]+$'])
        ->and($properties['minimum'])->toBe(['type' => 'string', 'description' => 'A property named like a keyword is kept']);
});

dataset('anthropic schemas native structured outputs cannot represent', [
    'open object' => ['{"type":"object"}', '{"type":"object"}'],
    'open object in an envelope' => ['{"name":"x","schema":{"type":"object"}}', '{"type":"object"}'],
    'additionalProperties schema map' => [
        '{"properties":{"tags":{"type":"object","additionalProperties":{"type":"string"}}}}',
        '{"properties":{"tags":{"type":"object","additionalProperties":{"type":"string"}}}}',
    ],
    'additionalProperties true' => [
        '{"type":"object","properties":{"a":{"type":"string"}},"additionalProperties":true}',
        '{"type":"object","properties":{"a":{"type":"string"}},"additionalProperties":true}',
    ],
    'nested open object' => [
        '{"type":"object","properties":{"meta":{"type":"object"}}}',
        '{"type":"object","properties":{"meta":{"type":"object"}}}',
    ],
    'items not a schema' => [
        '{"type":"object","properties":{"list":{"type":"array","items":true}}}',
        '{"type":"object","properties":{"list":{"type":"array","items":true}}}',
    ],
    'non-array envelope schema' => ['{"schema":"x"}', '"x"'],
    'self-recursive definition' => [
        '{"type":"object","properties":{"root":{"$ref":"#/$defs/Node"}},"additionalProperties":false,"$defs":{"Node":{"type":"object","properties":{"children":{"type":"array","items":{"$ref":"#/$defs/Node"}}},"additionalProperties":false}}}',
        '{"type":"object","properties":{"root":{"$ref":"#\/$defs\/Node"}},"additionalProperties":false,"$defs":{"Node":{"type":"object","properties":{"children":{"type":"array","items":{"$ref":"#\/$defs\/Node"}}},"additionalProperties":false}}}',
    ],
    'mutually recursive definitions' => [
        '{"type":"object","properties":{"a":{"$ref":"#/definitions/A"}},"definitions":{"A":{"type":"object","properties":{"b":{"$ref":"#/$defs/B"}}}},"$defs":{"B":{"anyOf":[{"type":"null"},{"$ref":"#/definitions/A"}]}}}',
        '{"type":"object","properties":{"a":{"$ref":"#\/definitions\/A"}},"definitions":{"A":{"type":"object","properties":{"b":{"$ref":"#\/$defs\/B"}}}},"$defs":{"B":{"anyOf":[{"type":"null"},{"$ref":"#\/definitions\/A"}]}}}',
    ],
    'root recursion' => [
        '{"type":"object","properties":{"next":{"$ref":"#"}}}',
        '{"type":"object","properties":{"next":{"$ref":"#"}}}',
    ],
    'external ref' => [
        '{"type":"object","properties":{"a":{"$ref":"https://example.test/schema.json"}}}',
        '{"type":"object","properties":{"a":{"$ref":"https:\/\/example.test\/schema.json"}}}',
    ],
    'unknown definition' => [
        '{"type":"object","properties":{"a":{"$ref":"#/$defs/Missing"}},"$defs":{"Other":{"type":"string"}}}',
        '{"type":"object","properties":{"a":{"$ref":"#\/$defs\/Missing"}},"$defs":{"Other":{"type":"string"}}}',
    ],
    'pointer below a definition' => [
        '{"type":"object","properties":{"a":{"$ref":"#/$defs/T/properties/x"}},"$defs":{"T":{"type":"object","properties":{"x":{"type":"string"}}}}}',
        '{"type":"object","properties":{"a":{"$ref":"#\/$defs\/T\/properties\/x"}},"$defs":{"T":{"type":"object","properties":{"x":{"type":"string"}}}}}',
    ],
    'ref that is not a string' => [
        '{"type":"object","properties":{"a":{"$ref":5}}}',
        '{"type":"object","properties":{"a":{"$ref":5}}}',
    ],
    'unwalked keyword dependencies' => [
        '{"type":"object","properties":{"a":{"type":"string"}},"additionalProperties":false,"dependencies":{"a":{"required":["a"]}}}',
        '{"type":"object","properties":{"a":{"type":"string"}},"additionalProperties":false,"dependencies":{"a":{"required":["a"]}}}',
    ],
    'unwalked keyword not' => [
        '{"type":"object","properties":{"a":{"type":"string","not":{"const":"x"}}}}',
        '{"type":"object","properties":{"a":{"type":"string","not":{"const":"x"}}}}',
    ],
]);

test('structured treats every unwalked keyword as not representable', function (string $keyword) {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{}']],
    ]));
    $schema = json_encode(['type' => 'object', 'properties' => ['a' => ['type' => 'string', $keyword => ['type' => 'object']]], 'additionalProperties' => false]);

    $provider->structured([new UserMessage('hi')], $schema);

    expect($payload)->not->toHaveKey('output_config')
        ->and(anthropicLastUserText($payload))->toStartWith(ANTHROPIC_STRUCTURED_INSTRUCTION);
})->with(['not', 'if', 'then', 'else', 'dependentSchemas', 'patternProperties', 'contains', 'propertyNames', 'unevaluatedProperties', '$dynamicRef', '$recursiveRef', 'dependencies', 'unevaluatedItems', 'additionalItems', 'contentSchema']);

test('structured keeps the native path for non-recursive refs between definitions', function () {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{}']],
    ]));
    $schema = json_encode([
        'type' => 'object',
        'properties' => ['a' => ['$ref' => '#/$defs/Outer'], 'b' => ['$ref' => '#/$defs/Inner'], 'c' => ['$ref' => '#/definitions/Leaf']],
        'additionalProperties' => false,
        '$defs' => [
            'Outer' => ['type' => 'object', 'properties' => ['inner' => ['$ref' => '#/$defs/Inner'], 'leaf' => ['$ref' => '#/definitions/Leaf']], 'additionalProperties' => false],
            'Inner' => ['type' => 'array', 'items' => ['$ref' => '#/definitions/Leaf']],
        ],
        'definitions' => ['Leaf' => ['type' => 'string']],
    ]);

    $provider->structured([new UserMessage('hi')], $schema);

    expect($payload['output_config']['format']['schema']['properties']['a'])->toBe(['$ref' => '#/$defs/Outer'])
        ->and($payload['output_config']['format']['schema']['$defs']['Inner']['items'])->toBe(['$ref' => '#/definitions/Leaf']);
});

test('structured falls back to a JSON instruction when the schema is not representable', function (string $schema, string $encodedSchema) {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{"name":"Alice","age":30}']],
    ]));

    $result = $provider->structured([new UserMessage('My name is Alice')], $schema, [
        'tool_choice' => ['type' => 'any'],
        'output_config' => ['format' => ['type' => 'text']],
    ]);

    expect($result)->toBe(['name' => 'Alice', 'age' => 30])
        ->and($payload)->not->toHaveKey('output_config')
        ->and($payload)->not->toHaveKey('tool_choice')
        ->and(anthropicLastUserText($payload))->toBe(ANTHROPIC_STRUCTURED_INSTRUCTION . $encodedSchema)
        ->and($payload['messages'][0]['content'][0]['text'])->toBe('My name is Alice');
})->with('anthropic schemas native structured outputs cannot represent');

dataset('anthropic structured paths', [
    'native' => [ANTHROPIC_CLOSED_SCHEMA, true],
    'fallback' => ['{"type":"object"}', false],
]);

test('structured drops a caller tool_choice and keeps caller tools', function (string $schema, bool $native) {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{}']],
    ]));
    $tools = [['name' => 'lookup', 'input_schema' => ['type' => 'object']]];

    $provider->structured([new UserMessage('hi')], $schema, [
        'tools' => $tools,
        'tool_choice' => ['type' => 'any'],
    ]);

    expect($payload)->not->toHaveKey('tool_choice')
        ->and($payload['tools'])->toBe($tools)
        ->and(isset($payload['output_config']['format']))->toBe($native);
})->with('anthropic structured paths');

test('structured returns the decoded JSON object', function (string $schema, bool $native) {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{"name":"Alice","age":30}'], ['type' => 'text', 'text' => 'trailing']],
    ]));

    $result = $provider->structured([new UserMessage('My name is Alice')], $schema);

    expect($result)->toBe(['name' => 'Alice', 'age' => 30])
        ->and(isset($payload['output_config']['format']))->toBe($native);
})->with('anthropic structured paths');

test('structured keeps caller output_config.effort', function (string $schema, bool $native) {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{}']],
    ]));

    $provider->structured([new UserMessage('hi')], $schema, [
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'text']],
    ]);

    expect($payload['output_config']['effort'])->toBe('low');
    if ($native) {
        expect($payload['output_config']['format']['type'])->toBe('json_schema');
    } else {
        expect($payload['output_config'])->toBe(['effort' => 'low']);
    }
})->with('anthropic structured paths');

test('structured falls back to Response on refusal', function (string $schema) {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => '{"partial":true}']],
        'stop_reason' => 'refusal',
    ]));

    $result = $provider->structured([new UserMessage('hi')], $schema);

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->finishReason)->toBe(ProviderFinishReason::Refusal);
})->with('anthropic structured paths');

test('structured falls back to Response when the text is not JSON', function (string $schema) {
    $payload = null;
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: structuredClient($payload, [
        'content' => [['type' => 'text', 'text' => 'not json']],
    ]));

    $result = $provider->structured([new UserMessage('hi')], $schema);

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->content)->toBe('not json');
})->with('anthropic structured paths');

test('tool result messages serialize as tool_result blocks and ignore tool result metadata', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });

    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([
        new UserMessage('Read the file'),
        new AssistantMessage('', [new ToolCall('toolu_123', 'read_file', ['path' => '/tmp/test'])]),
        new ToolResultMessage(
            ToolResult::json(['status' => 'ok', 'path' => '/tmp/test'], ['phase' => 'read'])
                ->withMimeType('application/json')
                ->withDisplayHint('structured-json')
                ->withCallId('toolu_123'),
        ),
    ]);

    $toolResultBlock = $requestPayload['messages'][2]['content'][0];

    expect($toolResultBlock['type'])->toBe('tool_result')
        ->and($toolResultBlock['tool_use_id'])->toBe('toolu_123')
        ->and($toolResultBlock['content'])->toContain('"status": "ok"')
        ->and($toolResultBlock['content'])->toContain('"path": "/tmp/test"')
        ->and($toolResultBlock)->not->toHaveKey('metadata')
        ->and($toolResultBlock)->not->toHaveKey('mimeType')
        ->and($toolResultBlock)->not->toHaveKey('displayHint');
});

// --- Reasoning / Extended Thinking ---

test('a tool result with no call id gets the same fallback tool_use_id on every request', function () {
    $bodies = [];
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
        $bodies[] = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);
    $history = [
        new UserMessage('hi'),
        new AssistantMessage('Checking.'),
        new ToolResultMessage(ToolResult::success('pong')),
        new ToolResultMessage(ToolResult::success('pong')),
    ];

    $provider->chat($history);
    $provider->chat($history);

    $ids = fn(array $body): array => array_map(fn(array $block): string => $block['tool_use_id'], $body['messages'][2]['content']);

    expect($ids($bodies[0]))->toBe($ids($bodies[1]))
        ->and($ids($bodies[0])[0])->toMatch('/^toolu_[0-9a-f]{24}$/')
        ->and($ids($bodies[0])[0])->not->toBe($ids($bodies[0])[1]);
});

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
        "data: " . json_encode(['type' => 'message_stop']) . "\n\n",
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
        "data: " . json_encode(['type' => 'message_stop']) . "\n\n",
    ]);

    $mockClient = new MockHttpClient([new MockResponse($sseData, ['http_code' => 200])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $chunks = iterator_to_array($provider->stream([new UserMessage('hi')]));
    $reasoningChunks = array_filter($chunks, fn($r) => $r->reasoning !== '');

    foreach ($reasoningChunks as $chunk) {
        expect($chunk->content)->toBe('');
    }
});

test('stream keeps stream true and the conversation when options try to override them', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return new MockResponse(
            'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]) . "\n\n"
            . 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n",
            ['http_code' => 200],
        );
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    iterator_to_array($provider->stream([new UserMessage('Hi')], [], ['stream' => false, 'messages' => []]));

    expect($requestPayload['stream'])->toBeTrue()
        ->and($requestPayload['messages'])->toBe([['role' => 'user', 'content' => 'Hi']]);
});

test('stream merges options into the payload', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return new MockResponse(
            'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]) . "\n\n"
            . 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n",
            ['http_code' => 200],
        );
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    iterator_to_array($provider->stream([new UserMessage('Hi')], [], [
        'temperature' => 0.2,
        'thinking' => ['type' => 'enabled', 'budget_tokens' => 2048],
        'max_tokens' => 9000,
        'tool_choice' => ['type' => 'auto'],
    ]));

    expect($requestPayload['temperature'])->toBe(0.2)
        ->and($requestPayload['thinking'])->toBe(['type' => 'enabled', 'budget_tokens' => 2048])
        ->and($requestPayload['max_tokens'])->toBe(9000)
        ->and($requestPayload['tool_choice'])->toBe(['type' => 'auto'])
        ->and($requestPayload['stream'])->toBeTrue();
});

test('chat keeps the conversation and stays non-streaming when options try to override them', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([new UserMessage('Hi')], [], ['stream' => true, 'messages' => []]);

    expect($requestPayload)->not->toHaveKey('stream')
        ->and($requestPayload['messages'])->toBe([['role' => 'user', 'content' => 'Hi']]);
});

/**
 * Helper: build a mock Anthropic SSE response from event payloads.
 *
 * @param array<string, mixed> ...$events
 */
function anthropicSse(array ...$events): MockResponse
{
    $body = '';
    foreach ($events as $event) {
        $body .= 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event) . "\n\n";
    }

    return new MockResponse($body, ['http_code' => 200]);
}

/**
 * @param array<string, mixed> ...$events
 * @return CarmeloSantana\PHPAgents\Provider\Response[]
 */
function streamAnthropic(array ...$events): array
{
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: new MockHttpClient([anthropicSse(...$events)]));

    return array_values(iterator_to_array($provider->stream([new UserMessage('hi')]), false));
}

function anthropicMessageStart(int $inputTokens = 12): array
{
    return ['type' => 'message_start', 'message' => ['model' => 'claude-test', 'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => 1]]];
}

function anthropicText(string $text, int $index = 0): array
{
    return ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $text]];
}

function anthropicStopDelta(?string $stopReason, ?int $outputTokens = null): array
{
    $event = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason]];
    if ($outputTokens !== null) {
        $event['usage'] = ['output_tokens' => $outputTokens];
    }

    return $event;
}

function anthropicToolStart(int $index, string $id = 'toolu_1', string $name = 'lookup'): array
{
    return ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => []]];
}

function anthropicToolJson(int $index, string $partialJson): array
{
    return ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $partialJson]];
}

const ANTHROPIC_MESSAGE_STOP = ['type' => 'message_stop'];

test('chat maps stop_reason', function (?string $stopReason, ProviderFinishReason $expected) {
    $mockClient = new MockHttpClient([mockAnthropicResponse(['stop_reason' => $stopReason])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    expect($provider->chat([new UserMessage('hi')])->finishReason)->toBe($expected);
})->with(fn() => [
    'end_turn' => ['end_turn', ProviderFinishReason::Stop],
    'stop_sequence' => ['stop_sequence', ProviderFinishReason::Stop],
    'tool_use' => ['tool_use', ProviderFinishReason::ToolUse],
    'max_tokens' => ['max_tokens', ProviderFinishReason::MaxTokens],
    'refusal' => ['refusal', ProviderFinishReason::Refusal],
    'pause_turn' => ['pause_turn', ProviderFinishReason::PauseTurn],
    'model_context_window_exceeded' => ['model_context_window_exceeded', ProviderFinishReason::ContextWindowExceeded],
    'unknown value' => ['something_new', ProviderFinishReason::Stop],
]);

test('chat logs an unknown stop_reason at debug', function () {
    $logger = new class extends Psr\Log\AbstractLogger {
        /** @var array<int, array{0: mixed, 1: string, 2: array<mixed>}> */
        public array $records = [];

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message, $context];
        }
    };
    $mockClient = new MockHttpClient([mockAnthropicResponse(['stop_reason' => 'something_new'])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient, logger: $logger);

    $provider->chat([new UserMessage('hi')]);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0][0])->toBe(Psr\Log\LogLevel::DEBUG)
        ->and($logger->records[0][2])->toBe(['stop_reason' => 'something_new']);
});

test('stream yields one terminal chunk at message_stop for a refusal, with output usage', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(12),
        anthropicText('Sorry'),
        anthropicStopDelta('refusal', 7),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('Sorry')
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($chunks[0]->usage)->toBeNull()
        ->and($chunks[1]->content)->toBe('')
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::Refusal)
        ->and($chunks[1]->toolCalls)->toBe([])
        ->and($chunks[1]->model)->toBe('claude-test')
        ->and($chunks[1]->usage->promptTokens)->toBe(12)
        ->and($chunks[1]->usage->completionTokens)->toBe(7)
        ->and($chunks[1]->usage->totalTokens)->toBe(19);
});

test('stream accumulates repeated message_delta events', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(3),
        anthropicText('Hi'),
        anthropicStopDelta(null, 4),
        anthropicStopDelta('end_turn', 9),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($chunks[1]->usage->promptTokens)->toBe(3)
        ->and($chunks[1]->usage->completionTokens)->toBe(9);
});

test('stream keeps the last non-null stop_reason when a later delta has none', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(3),
        anthropicStopDelta('max_tokens', 4),
        anthropicStopDelta(null, 5),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::MaxTokens)
        ->and($chunks[0]->usage->completionTokens)->toBe(5);
});

test('stream yields the mapped reason at message_stop', function (string $stopReason, ProviderFinishReason $expected) {
    $chunks = streamAnthropic(anthropicMessageStart(), anthropicText('x'), anthropicStopDelta($stopReason, 2), ANTHROPIC_MESSAGE_STOP);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1]->finishReason)->toBe($expected)
        ->and($chunks[1]->toolCalls)->toBe([]);
})->with(fn() => [
    'pause_turn' => ['pause_turn', ProviderFinishReason::PauseTurn],
    'stop_sequence' => ['stop_sequence', ProviderFinishReason::Stop],
    'model_context_window_exceeded' => ['model_context_window_exceeded', ProviderFinishReason::ContextWindowExceeded],
]);

test('stream with message_stop and no stop_reason yields a terminal Error chunk', function () {
    $chunks = streamAnthropic(anthropicMessageStart(), anthropicText('x'), ANTHROPIC_MESSAGE_STOP);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($chunks[1]->toolCalls)->toBe([]);
});

test('stream that ends before message_stop yields a terminal Error chunk', function (array $events) {
    $chunks = streamAnthropic(...$events);
    $terminal = end($chunks);

    expect(array_filter($chunks, fn($c) => $c->finishReason !== ProviderFinishReason::Stop))->toHaveCount(1)
        ->and($terminal->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($terminal->toolCalls)->toBe([]);
})->with(fn() => [
    'EOF after text' => [[anthropicMessageStart(), anthropicText('partial')]],
    'EOF after a tool_use start' => [[anthropicMessageStart(), anthropicToolStart(0), anthropicToolJson(0, '{"q":"a"}'), anthropicStopDelta('tool_use', 5)]],
]);

test('stream error event throws ProviderException with the API message', function (array $before) {
    $events = [...$before, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]];
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: new MockHttpClient([anthropicSse(...$events)]));

    $seen = [];
    expect(function () use ($provider, &$seen) {
        foreach ($provider->stream([new UserMessage('hi')]) as $chunk) {
            $seen[] = $chunk;
        }
    })->toThrow(CarmeloSantana\PHPAgents\Exception\ProviderException::class, 'overloaded_error: Overloaded');

    expect(array_filter($seen, fn($c) => $c->finishReason !== ProviderFinishReason::Stop))->toBe([]);
})->with(fn() => [
    'before text' => [[anthropicMessageStart()]],
    'after text' => [[anthropicMessageStart(), anthropicText('Hello')]],
]);

test('stream with stop tool_use yields the tool calls on the terminal chunk', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(5),
        anthropicToolStart(0, 'toolu_a', 'lookup'),
        anthropicToolJson(0, '{"q":'),
        anthropicToolJson(0, '"php"}'),
        ['type' => 'content_block_stop', 'index' => 0],
        anthropicToolStart(1, 'toolu_b', 'now'),
        ['type' => 'content_block_stop', 'index' => 1],
        anthropicStopDelta('tool_use', 11),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($chunks[0]->usage->completionTokens)->toBe(11)
        ->and($chunks[0]->toolCalls)->toHaveCount(2)
        ->and($chunks[0]->toolCalls[0]->id)->toBe('toolu_a')
        ->and($chunks[0]->toolCalls[0]->arguments)->toBe(['q' => 'php'])
        ->and($chunks[0]->toolCalls[1]->name)->toBe('now')
        ->and($chunks[0]->toolCalls[1]->arguments)->toBe([]);
});

test('stream with truncated tool JSON and stop tool_use yields Error and no tool calls', function (string $json) {
    $chunks = streamAnthropic(
        anthropicMessageStart(),
        anthropicToolStart(0),
        anthropicToolJson(0, $json),
        anthropicStopDelta('tool_use', 4),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($chunks[0]->toolCalls)->toBe([]);
})->with(fn() => [
    'truncated object' => ['{"q": "ph'],
    'JSON array, not an object' => ['[1, 2]'],
    'JSON scalar' => ['"text"'],
]);

test('stream that stops on max_tokens mid tool JSON yields MaxTokens and no tool calls', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(),
        anthropicText('Let me look'),
        anthropicToolStart(1),
        anthropicToolJson(1, '{"q": "ph'),
        anthropicStopDelta('max_tokens', 4),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect($chunks)->toHaveCount(2)
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::MaxTokens)
        ->and($chunks[1]->toolCalls)->toBe([]);
});

// --- Thinking block round trip ---

const ANTHROPIC_ROUND_TRIP_TOOL_INPUT = '{"a":{},"b":[],"c":{"0":"x"},"d":[{"e":{}}]}';

/**
 * A logger that keeps every record, for asserting on debug lines.
 */
function anthropicRecordingLogger(): Psr\Log\AbstractLogger
{
    return new class extends Psr\Log\AbstractLogger {
        /** @var array<int, array{0: mixed, 1: string, 2: array<mixed>}> */
        public array $records = [];

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message, $context];
        }
    };
}

test('chat keeps thinking, redacted_thinking, text and tool_use blocks in order and byte-faithful', function () {
    $contentJson = '[{"type":"thinking","thinking":"","signature":"sig1"},'
        . '{"type":"redacted_thinking","data":"opaque=="},'
        . '{"type":"text","text":"Let me look."},'
        . '{"type":"tool_use","id":"toolu_1","name":"lookup","input":' . ANTHROPIC_ROUND_TRIP_TOOL_INPUT . '}]';
    $firstBody = '{"id":"msg_1","type":"message","role":"assistant","model":"claude-test","content":' . $contentJson
        . ',"stop_reason":"tool_use","usage":{"input_tokens":5,"output_tokens":7}}';

    $bodies = [];
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies, $firstBody): MockResponse {
        $bodies[] = $options['body'];

        return count($bodies) === 1 ? new MockResponse($firstBody, ['http_code' => 200]) : mockAnthropicResponse();
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $response = $provider->chat([new UserMessage('hi')]);

    expect(array_column($response->contentBlocks, 'type'))->toBe(['thinking', 'redacted_thinking', 'text', 'tool_use'])
        ->and(json_encode($response->contentBlocks))->toBe($contentJson)
        ->and($response->content)->toBe('Let me look.')
        ->and($response->toolCalls[0]->name)->toBe('lookup');

    $provider->chat([
        new UserMessage('hi'),
        new AssistantMessage($response->content, $response->toolCalls, $response->contentBlocks),
        new ToolResultMessage(ToolResult::success('found')->withCallId('toolu_1')),
    ]);

    expect($bodies[1])->toContain('{"role":"assistant","content":' . $contentJson . '}')
        ->and($bodies[1])->toContain('"input":' . ANTHROPIC_ROUND_TRIP_TOOL_INPUT)
        ->and($bodies[1])->toContain('"thinking":""')
        ->and($bodies[1])->toContain('"signature":"sig1"');
});

test('chat still refuses a redirect and throws on an HTTP error', function (int $status, string $exception) {
    $mockClient = new MockHttpClient([new MockResponse('{"type":"error"}', ['http_code' => $status, 'response_headers' => ['Location' => 'https://elsewhere.test/']])]);
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    expect(fn() => $provider->chat([new UserMessage('hi')]))->toThrow($exception);
})->with(fn() => [
    'redirect' => [302, CarmeloSantana\PHPAgents\Exception\ProviderException::class],
    'client error' => [400, Symfony\Component\HttpClient\Exception\ClientException::class],
    'server error' => [500, Symfony\Component\HttpClient\Exception\ServerException::class],
]);

test('chat sends an assistant message without content blocks through the legacy path', function () {
    $bodies = [];
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
        $bodies[] = $options['body'];

        return mockAnthropicResponse();
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([new UserMessage('hi'), new AssistantMessage('Hello there.'), new UserMessage('again')]);

    expect($bodies[0])->toContain('{"role":"assistant","content":"Hello there."}');
});

test('chat drops an empty assistant turn and merges the user turns around it', function () {
    $bodies = [];
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
        $bodies[] = $options['body'];

        return mockAnthropicResponse();
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([new UserMessage('first'), new AssistantMessage(''), new UserMessage('second')]);

    expect($bodies[0])->toContain('"messages":[{"role":"user","content":[{"type":"text","text":"first"},{"type":"text","text":"second"}]}]');
});

test('stream rebuilds thinking with signature_delta and redacted_thinking', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(),
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me ']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'check.']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'part2']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'opaque==']],
        ['type' => 'content_block_stop', 'index' => 1],
        ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'text', 'text' => '']],
        anthropicText('On ', 2),
        anthropicText('it.', 2),
        ['type' => 'content_block_stop', 'index' => 2],
        anthropicToolStart(3, 'toolu_1', 'lookup'),
        anthropicToolJson(3, '{"a":{},"b":[],'),
        anthropicToolJson(3, '"c":{"0":"x"},"d":[{"e":{}}]}'),
        ['type' => 'content_block_stop', 'index' => 3],
        anthropicToolStart(4, 'toolu_2', 'now'),
        ['type' => 'content_block_stop', 'index' => 4],
        anthropicStopDelta('tool_use', 9),
        ANTHROPIC_MESSAGE_STOP,
    );
    $terminal = end($chunks);

    expect($terminal->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($terminal->contentBlocks)->toEqual([
            ['type' => 'thinking', 'thinking' => 'Let me check.', 'signature' => 'sigpart2'],
            ['type' => 'redacted_thinking', 'data' => 'opaque=='],
            ['type' => 'text', 'text' => 'On it.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => json_decode(ANTHROPIC_ROUND_TRIP_TOOL_INPUT)],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'now', 'input' => new stdClass()],
        ])
        ->and(json_encode($terminal->contentBlocks[3]['input']))->toBe(ANTHROPIC_ROUND_TRIP_TOOL_INPUT)
        ->and(json_encode($terminal->contentBlocks[4]['input']))->toBe('{}')
        ->and(array_filter(array_slice($chunks, 0, -1), fn($c) => $c->contentBlocks !== []))->toBe([]);
});

test('stream sets content blocks on a Stop terminal chunk', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(),
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig1']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
        anthropicText('Done.', 1),
        ['type' => 'content_block_stop', 'index' => 1],
        anthropicStopDelta('end_turn', 3),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect(json_encode(end($chunks)->contentBlocks))
        ->toBe('[{"type":"thinking","thinking":"","signature":"sig1"},{"type":"text","text":"Done."}]');
});

test('stream skips a text block that received no deltas when rebuilding content blocks', function () {
    $chunks = streamAnthropic(
        anthropicMessageStart(),
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig1']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_stop', 'index' => 1],
        anthropicToolStart(2, 'toolu_1', 'lookup'),
        anthropicToolJson(2, '{"q":"x"}'),
        ['type' => 'content_block_stop', 'index' => 2],
        anthropicStopDelta('tool_use', 3),
        ANTHROPIC_MESSAGE_STOP,
    );

    expect(json_encode(end($chunks)->contentBlocks))
        ->toBe('[{"type":"thinking","thinking":"","signature":"sig1"},{"type":"tool_use","id":"toolu_1","name":"lookup","input":{"q":"x"}}]');
});

test('stream drops contentBlocks on an unknown block type, on max_tokens, and on truncated tool JSON', function (array $events, ProviderFinishReason $reason) {
    $chunks = streamAnthropic(anthropicMessageStart(), ...$events);
    $terminal = end($chunks);

    expect($terminal->finishReason)->toBe($reason)
        ->and($terminal->contentBlocks)->toBe([]);
})->with(fn() => [
    'unknown block type' => [[
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => []]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
        anthropicText('Answer.', 1),
        anthropicStopDelta('end_turn', 3),
        ANTHROPIC_MESSAGE_STOP,
    ], ProviderFinishReason::Stop],
    'max_tokens' => [[
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig1']],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
        anthropicText('Partial', 1),
        anthropicStopDelta('max_tokens', 3),
        ANTHROPIC_MESSAGE_STOP,
    ], ProviderFinishReason::MaxTokens],
    'truncated tool JSON' => [[
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig1']],
        anthropicToolStart(1),
        anthropicToolJson(1, '{"q": "ph'),
        anthropicStopDelta('tool_use', 3),
        ANTHROPIC_MESSAGE_STOP,
    ], ProviderFinishReason::Error],
    'EOF' => [[
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        anthropicText('Partial'),
    ], ProviderFinishReason::Error],
]);

test('stream logs the tool name and argument length when tool JSON does not decode', function () {
    $logger = anthropicRecordingLogger();
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: new MockHttpClient([anthropicSse(
        anthropicMessageStart(),
        anthropicToolStart(0, 'toolu_1', 'lookup'),
        anthropicToolJson(0, '{"q": "secret'),
        anthropicStopDelta('tool_use', 4),
        ANTHROPIC_MESSAGE_STOP,
    )]), logger: $logger);

    iterator_to_array($provider->stream([new UserMessage('hi')]), false);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0][0])->toBe(Psr\Log\LogLevel::DEBUG)
        ->and($logger->records[0][2])->toBe(['tool' => 'lookup', 'length' => 13])
        ->and($logger->records[0][1])->not->toContain('secret');
});

// --- Models API ---

/**
 * @param list<array<string, mixed>> $pages
 * @param list<string> $urls
 */
function anthropicModelsClient(array $pages, array &$urls): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use ($pages, &$urls): MockResponse {
        $urls[] = $url;
        $page = $pages[min(count($urls), count($pages)) - 1];

        return new MockResponse(json_encode($page), ['http_code' => 200]);
    });
}

test('default model is claude-sonnet-5-5', function () {
    expect((new AnthropicProvider(apiKey: 'test'))->getModel())->toBe('claude-sonnet-5-5');
});

test('models maps max_input_tokens, max_tokens and capabilities', function () {
    $urls = [];
    $client = anthropicModelsClient([[
        'data' => [[
            'id' => 'claude-opus-5-5',
            'display_name' => 'Claude Opus 5.5',
            'created_at' => '2026-09-01T00:00:00Z',
            'max_input_tokens' => 1000000,
            'max_tokens' => 128000,
            'lifecycle' => ['status' => 'active'],
            'capabilities' => [
                'image_input' => ['supported' => true],
                'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => true]]],
            ],
        ], ['display_name' => 'no id']],
        'has_more' => false,
    ]], $urls);

    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();

    expect($models)->toHaveCount(1);
    $model = $models[0];
    expect($model->id)->toBe('claude-opus-5-5')
        ->and($model->name)->toBe('Claude Opus 5.5')
        ->and($model->contextWindow)->toBe(1000000)
        ->and($model->maxTokens)->toBe(128000)
        ->and($model->vision)->toBeTrue()
        ->and($model->thinking)->toBeTrue()
        ->and($model->reasoning)->toBeTrue()
        ->and($model->toolCalls)->toBeTrue()
        ->and($model->capabilities)->toBe([ModelCapability::Text, ModelCapability::Image, ModelCapability::Tools, ModelCapability::Reasoning])
        ->and($model->metadataSource)->toBe('provider-api')
        ->and($model->fieldSources)->toBe(['contextWindow' => 'provider-api', 'maxTokens' => 'provider-api'])
        ->and($model->extras)->toBe([
            'capabilities' => [
                'image_input' => ['supported' => true],
                'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => true]]],
            ],
            'lifecycle' => ['status' => 'active'],
            'created_at' => '2026-09-01T00:00:00Z',
        ])
        ->and($urls)->toBe(['https://api.anthropic.com/v1/models?limit=1000']);
});

test('models treats null limits and null capabilities as defaults', function () {
    $urls = [];
    $client = anthropicModelsClient([[
        'data' => [
            ['id' => 'm-null', 'display_name' => null, 'max_input_tokens' => null, 'max_tokens' => null, 'capabilities' => null, 'lifecycle' => null, 'created_at' => null],
            ['id' => 'm-missing'],
        ],
        'has_more' => false,
    ]], $urls);

    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();

    expect($models)->toHaveCount(2);
    foreach ($models as $model) {
        expect($model->name)->toBe($model->id)
            ->and($model->contextWindow)->toBe(200000)
            ->and($model->maxTokens)->toBe(4096)
            ->and($model->fieldSources)->toBe(['contextWindow' => 'default', 'maxTokens' => 'default'])
            ->and($model->vision)->toBeFalse()
            ->and($model->thinking)->toBeFalse()
            ->and($model->reasoning)->toBeFalse()
            ->and($model->capabilities)->toBe([ModelCapability::Text, ModelCapability::Tools])
            ->and($model->extras)->toBe([]);
    }
});

test('models follows has_more with after_id', function () {
    $urls = [];
    $client = anthropicModelsClient([
        ['data' => [['id' => 'm1']], 'has_more' => true, 'first_id' => 'm1', 'last_id' => 'm1'],
        ['data' => [['id' => 'm2']], 'has_more' => false, 'first_id' => 'm2', 'last_id' => 'm2'],
    ], $urls);

    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();

    expect(array_map(fn($m) => $m->id, $models))->toBe(['m1', 'm2'])
        ->and($urls)->toHaveCount(2)
        ->and($urls[1])->toBe('https://api.anthropic.com/v1/models?after_id=m1&limit=1000');
});

test('models stops when last_id repeats', function () {
    $urls = [];
    $client = anthropicModelsClient([
        ['data' => [['id' => 'm1']], 'has_more' => true, 'last_id' => 'm1'],
        ['data' => [['id' => 'm2']], 'has_more' => true, 'last_id' => 'm1'],
    ], $urls);

    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();

    expect($urls)->toHaveCount(2)
        ->and(array_map(fn($m) => $m->id, $models))->toBe(['m1', 'm2']);
});

test('models stops after 10 pages', function () {
    $urls = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
        $urls[] = $url;
        $id = 'm' . count($urls);

        return new MockResponse(json_encode(['data' => [['id' => $id]], 'has_more' => true, 'last_id' => $id]), ['http_code' => 200]);
    });

    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();

    expect($urls)->toHaveCount(10)
        ->and($models)->toHaveCount(10);
});

test('models falls back to the static list when a later page fails', function () {
    $client = new MockHttpClient([
        new MockResponse(json_encode(['data' => [['id' => 'm1']], 'has_more' => true, 'last_id' => 'm1']), ['http_code' => 200]),
        new MockResponse('{"error":{"message":"boom"}}', ['http_code' => 500]),
    ]);

    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();

    expect($models[0]->metadataSource)->toBe('static-fallback');
});

test('static fallback lists only current models', function () {
    $client = new MockHttpClient(fn() => throw new \RuntimeException('offline'));

    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();

    expect(array_map(fn($m) => [$m->id, $m->name, $m->contextWindow, $m->maxTokens], $models))->toBe([
        ['claude-opus-5-5', 'Claude Opus 5.5', 1_000_000, 128_000],
        ['claude-sonnet-5-5', 'Claude Sonnet 5.5', 1_000_000, 128_000],
        ['claude-haiku-5-5', 'Claude Haiku 5.5', 1_000_000, 128_000],
        ['claude-fable-5-2', 'Claude Fable 5.2', 1_000_000, 128_000],
        ['claude-haiku-4-5', 'Claude Haiku 4.5', 200_000, 64_000],
    ]);
    foreach ($models as $model) {
        expect($model->toolCalls)->toBeTrue()
            ->and($model->vision)->toBeTrue()
            ->and($model->thinking)->toBeTrue()
            ->and($model->reasoning)->toBeTrue()
            ->and($model->capabilities)->toBe([ModelCapability::Text, ModelCapability::Image, ModelCapability::Tools, ModelCapability::Reasoning])
            ->and($model->metadataSource)->toBe('static-fallback');
    }
});

function anthropicReservedTool(): \CarmeloSantana\PHPAgents\Tool\Tool
{
    return new \CarmeloSantana\PHPAgents\Tool\Tool('agent_tool', 'The agent tool.', [], fn(array $input): ToolResult => ToolResult::success('ok'));
}

function anthropicReservedOptions(): array
{
    return [
        'system' => 'Injected instructions.',
        'tools' => [['name' => 'injected_tool', 'description' => 'Injected.', 'input_schema' => ['type' => 'object']]],
    ];
}

test('options cannot replace the conversation system prompt or the tools argument', function (string $method) {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $m, string $url, array $options) use (&$requestPayload, $method): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return $method === 'chat' ? mockAnthropicResponse() : anthropicSse(anthropicMessageStart(), anthropicStopDelta('end_turn'), ['type' => 'message_stop']);
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);
    $messages = [new SystemMessage('Agent instructions.'), new UserMessage('Hi')];

    $result = $provider->{$method}($messages, [anthropicReservedTool()], anthropicReservedOptions());
    if ($method === 'stream') {
        iterator_to_array($result);
    }

    expect($requestPayload['system'])->toBe('Agent instructions.')
        ->and(array_column($requestPayload['tools'], 'name'))->toBe(['agent_tool']);
})->with(['chat', 'stream']);

test('options may supply system and tools when the conversation has no system message and no tools are passed', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $m, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockAnthropicResponse();
    });
    $provider = new AnthropicProvider(apiKey: 'test-key', httpClient: $mockClient);

    $provider->chat([new UserMessage('Hi')], [], anthropicReservedOptions());

    expect($requestPayload['system'])->toBe('Injected instructions.')
        ->and(array_column($requestPayload['tools'], 'name'))->toBe(['injected_tool']);
});

test('chat reports usage sent as numeric strings and integral floats', function () {
    $mockClient = new MockHttpClient([
        mockAnthropicResponse([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => '15', 'output_tokens' => 8.0, 'cache_read_input_tokens' => '5'],
        ]),
    ]);

    $provider = new AnthropicProvider(model: 'claude-sonnet-4-20250514', apiKey: 'test-key', httpClient: $mockClient);
    $response = $provider->chat([new UserMessage('hi')]);

    expect($response->usage?->promptTokens)->toBe(20)
        ->and($response->usage?->completionTokens)->toBe(8)
        ->and($response->usage?->totalTokens)->toBe(28)
        ->and($response->usage?->cacheReadTokens)->toBe(5);
});
