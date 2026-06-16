<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Contract\ToolDocumentationInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @param array<string, mixed> $overrides
 */
function mockOllamaResponse(array $overrides = []): MockResponse
{
    $body = json_encode(array_merge([
        'id' => 'chatcmpl-ollama',
        'model' => 'llama3.2',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello from Ollama!'],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], $overrides), JSON_THROW_ON_ERROR);

    return new MockResponse($body, ['http_code' => 200]);
}

test('basic chat returns correct response', function () {
    $mockClient = new MockHttpClient([mockOllamaResponse()]);

    $provider = new OllamaProvider(
        model: 'llama3.2',
        httpClient: $mockClient,
    );

    $response = $provider->chat([new UserMessage('Hi')]);

    expect($response->content)->toBe('Hello from Ollama!')
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop);
});

test('num_ctx is injected when tools are present', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);

    $tool = new Tool(
        name: 'test_tool',
        description: 'Test',
        parameters: [new StringParameter('input', 'Input', required: true)],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );

    $provider->chat([new UserMessage('Hi')], [$tool]);

    expect($requestPayload['num_ctx'])->toBe(65536);
});

test('num_ctx not injected without tools', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);
    $provider->chat([new UserMessage('Hi')]);

    expect($requestPayload)->not->toHaveKey('num_ctx');
});

test('custom num_ctx is not overridden', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);

    $tool = new Tool(
        name: 'test_tool',
        description: 'Test',
        parameters: [new StringParameter('input', 'Input', required: true)],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );

    $provider->chat([new UserMessage('Hi')], [$tool], ['num_ctx' => 32768]);

    expect($requestPayload['num_ctx'])->toBe(32768);
});

test('schema sanitization removes unsupported keywords', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);

    // Create a tool with constraints that generate unsupported keywords
    $tool = new Tool(
        name: 'test_tool',
        description: 'Tool with constraints',
        parameters: [
            new NumberParameter('count', 'A number', required: true),
        ],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );

    $provider->chat([new UserMessage('test')], [$tool]);

    $params = $requestPayload['tools'][0]['function']['parameters'] ?? [];
    // Verify unsupported keywords are stripped from properties
    $countProp = $params['properties']['count'] ?? [];
    expect($countProp)->not->toHaveKey('additionalProperties')
        ->and($countProp)->not->toHaveKey('$ref')
        ->and($countProp)->not->toHaveKey('$defs');
});

test('constraint demotion appends to description', function () {
    // Test the sanitization by checking the description contains demoted constraints
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);

    // Create tool with known schema structure
    $tool = new Tool(
        name: 'test_tool',
        description: 'Test',
        parameters: [new StringParameter('name', 'User name', required: true)],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );

    $provider->chat([new UserMessage('test')], [$tool]);

    // Basic schema check — string params should be intact
    $props = $requestPayload['tools'][0]['function']['parameters']['properties'] ?? [];
    expect($props)->toHaveKey('name')
        ->and($props['name']['type'])->toBe('string');
});

test('tool documentation interface remains prompt-only in ollama tool payloads', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);

    $tool = new class implements ToolInterface, ToolDocumentationInterface {
        public function name(): string
        {
            return 'documented_tool';
        }

        public function description(): string
        {
            return 'Search documentation';
        }

        public function parameters(): array
        {
            return [new StringParameter('query', 'Search query', required: true)];
        }

        public function execute(array $input): ToolResult
        {
            return ToolResult::success('ok');
        }

        public function toFunctionSchema(): array
        {
            return (new Tool(
                name: $this->name(),
                description: $this->description(),
                parameters: $this->parameters(),
                callback: fn(array $input): ToolResult => $this->execute($input),
            ))->toFunctionSchema();
        }

        public function useWhen(): ?string
        {
            return 'Use this when you need to search docs.';
        }

        public function examples(): array
        {
            return ['query: "tool calling"'];
        }
    };

    $provider->chat([new UserMessage('Search docs')], [$tool]);

    $function = $requestPayload['tools'][0]['function'];

    expect($requestPayload['num_ctx'])->toBe(65536)
        ->and($function['name'])->toBe('documented_tool')
        ->and($function['description'])->toBe('Search documentation')
        ->and($function['parameters']['properties']['query']['type'])->toBe('string')
        ->and($function)->not->toHaveKey('examples')
        ->and($function)->not->toHaveKey('useWhen')
        ->and($function)->not->toHaveKey('use_when');
});

test('tool result json helper stays string-only in ollama message payloads', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);

    $provider->chat([
        new UserMessage('Read the file'),
        new AssistantMessage('', [new ToolCall('call_1', 'read_file', ['path' => '/tmp/test'])]),
        new ToolResultMessage(
            ToolResult::json(['status' => 'ok', 'path' => '/tmp/test'], ['phase' => 'read'])
                ->withErrorCode('NONE')
                ->withCallId('call_1'),
        ),
    ]);

    $toolMessage = $requestPayload['messages'][2];

    expect($toolMessage['role'])->toBe('tool')
        ->and($toolMessage['tool_call_id'])->toBe('call_1')
        ->and($toolMessage['content'])->toContain('"status": "ok"')
        ->and($toolMessage['content'])->toContain('"path": "/tmp/test"')
        ->and($toolMessage)->not->toHaveKey('metadata')
        ->and($toolMessage)->not->toHaveKey('errorCode');
});

test('models() parses Ollama native API response', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => 'codellama:7b'],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
    ]);

    $provider = new OllamaProvider(httpClient: $mockClient);
    $models = $provider->models();

    expect($models)->toHaveCount(2)
        ->and($models[0]->id)->toBe('llama3.2:latest')
        ->and($models[1]->id)->toBe('codellama:7b')
        ->and($models[0]->provider)->toBe('ollama');
});

test('models() uses native API endpoint not OpenAI compat', function () {
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;
        return new MockResponse(json_encode(['models' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    });

    $provider = new OllamaProvider(
        baseUrl: 'http://localhost:11434/v1',
        httpClient: $mockClient,
    );

    $provider->models();

    expect($capturedUrl)->toBe('http://localhost:11434/api/tags');
});

test('isAvailable uses native API', function () {
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;
        return new MockResponse(json_encode(['models' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    });

    $provider = new OllamaProvider(httpClient: $mockClient);
    $result = $provider->isAvailable();

    expect($result)->toBeTrue()
        ->and($capturedUrl)->toContain('/api/tags');
});

test('isAvailable returns false on connection error', function () {
    $mockClient = new MockHttpClient(function (): MockResponse {
        throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');
    });

    $provider = new OllamaProvider(httpClient: $mockClient);

    expect($provider->isAvailable())->toBeFalse();
});

test('models() returns empty array on error', function () {
    $mockClient = new MockHttpClient(function (): MockResponse {
        throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');
    });

    $provider = new OllamaProvider(httpClient: $mockClient);

    expect($provider->models())->toBeEmpty();
});

test('hasModel checks model list', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => 'codellama:7b'],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
    ]);

    $provider = new OllamaProvider(httpClient: $mockClient);

    expect($provider->hasModel('llama3.2:latest'))->toBeTrue();
});

test('hasModel matches latest alias without broad prefix matching', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'models' => [
                ['name' => 'llama3.2:latest'],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
    ]);

    $provider = new OllamaProvider(httpClient: $mockClient);

    expect($provider->hasModel('llama3.2'))->toBeTrue();
});

test('hasModel does not match arbitrary prefixes', function () {
    $mockClient = new MockHttpClient([
        new MockResponse(json_encode([
            'models' => [
                ['name' => 'gpt-5.4-2026-03-25'],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
    ]);

    $provider = new OllamaProvider(httpClient: $mockClient);

    expect($provider->hasModel('gpt-5.4'))->toBeFalse();
});

test('default apiKey is ollama-local', function () {
    $capturedHeaders = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
        $capturedHeaders = $options['normalized_headers'] ?? $options['headers'] ?? [];
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    // The provider should have been constructed with 'ollama-local' for the API key
    expect($capturedHeaders)->toBeArray();
});

test('custom numCtx is used', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient, numCtx: 131072);

    $tool = new Tool(
        name: 'test_tool',
        description: 'Test',
        parameters: [new StringParameter('x', 'X', required: true)],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );

    $provider->chat([new UserMessage('test')], [$tool]);

    expect($requestPayload['num_ctx'])->toBe(131072);
});

test('stream_options is absent from Ollama chat payload', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload)->not->toHaveKey('stream_options');
});

test('stream_options include_usage is sent in Ollama streaming payload', function () {
    $capturedPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedPayload): MockResponse {
        $capturedPayload = json_decode($options['body'], true);
        // Return SSE stream with a done event
        $body = "data: " . json_encode([
            'id' => 'chatcmpl-1',
            'choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => 'stop', 'index' => 0]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ]) . "\n\ndata: [DONE]\n\n";
        return new MockResponse($body, ['http_code' => 200]);
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);
    // Consume the generator fully
    foreach ($provider->stream([new UserMessage('hi')]) as $_) {}

    expect($capturedPayload)->toHaveKey('stream_options');
    expect($capturedPayload['stream_options'])->toBe(['include_usage' => true]);
});

test('reasoning_effort is sent when configured', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(
        model: 'qwen3:8b',
        httpClient: $mockClient,
        reasoningEffort: \CarmeloSantana\PHPAgents\Enum\ReasoningEffort::None,
    );
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload['reasoning_effort'])->toBe('none');
});

test('reasoning_effort is absent when not configured', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(model: 'llama3.2', httpClient: $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload)->not->toHaveKey('reasoning_effort');
});

test('explicit reasoning_effort option is not overridden', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return mockOllamaResponse();
    });

    $provider = new OllamaProvider(
        model: 'qwen3:8b',
        httpClient: $mockClient,
        reasoningEffort: \CarmeloSantana\PHPAgents\Enum\ReasoningEffort::None,
    );
    $provider->chat([new UserMessage('hi')], [], ['reasoning_effort' => 'high']);

    expect($requestPayload['reasoning_effort'])->toBe('high');
});
