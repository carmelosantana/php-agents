<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaServerProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\Image\ImageFixtures;
use Tests\Support\Runtime\FakeLocalModelRuntime;

/**
 * @param array<string, array<string, mixed>> $providers
 * @param array<string, ModelDefinition> $models
 */
function makeProviderFactoryConfig(array $providers = [], array $models = []): ConfigInterface
{
    return new class($providers, $models) implements ConfigInterface {
        /**
         * @param array<string, array<string, mixed>> $providers
         * @param array<string, ModelDefinition> $models
         */
        public function __construct(
            private array $providers,
            private array $models,
        ) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }

        public function has(string $key): bool
        {
            return false;
        }

        public function resolveModel(string $modelOrAlias): string
        {
            return $modelOrAlias;
        }

        public function getPrimaryModel(): string
        {
            return 'ollama/llama3.2';
        }

        public function getImageModel(): ?string
        {
            return null;
        }

        public function getProviderConfig(string $provider): array
        {
            return $this->providers[$provider] ?? [];
        }

        public function getModelDefinition(string $model): ?ModelDefinition
        {
            return $this->models[$model] ?? null;
        }
    };
}

test('parseModelString splits provider and model', function () {
    expect(ProviderFactory::parseModelString('openai/gpt-4o'))
        ->toBe(['openai', 'gpt-4o']);

    expect(ProviderFactory::parseModelString('anthropic/claude-sonnet-4-5'))
        ->toBe(['anthropic', 'claude-sonnet-4-5']);

    expect(ProviderFactory::parseModelString('xai/grok-4'))
        ->toBe(['xai', 'grok-4']);
});

test('parseModelString defaults to ollama when no slash', function () {
    expect(ProviderFactory::parseModelString('llama3.2:latest'))
        ->toBe(['ollama', 'llama3.2:latest']);
});

test('fromModelString routes ollama to OllamaProvider', function () {
    $provider = ProviderFactory::fromModelString('ollama/llama3.2');

    expect($provider)->toBeInstanceOf(OllamaProvider::class);
    expect($provider->getModel())->toBe('llama3.2');
});

test('fromModelString routes llama-cpp with a configured baseUrl to LlamaServerProvider', function (string $prefix) {
    $requests = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
        $requests[] = ['url' => $url, 'headers' => $options['headers'] ?? []];

        return new MockResponse(json_encode(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]), ['http_code' => 200]);
    });
    $config = makeProviderFactoryConfig([$prefix => ['baseUrl' => 'http://127.0.0.1:18089/v1', 'apiKey' => 'server-key']]);
    // A runtime injected alongside a baseUrl does not win: the configured server does.
    $runtime = new FakeLocalModelRuntime();

    $provider = ProviderFactory::fromModelString("{$prefix}/qwen2.5", $config, $client, $runtime);
    $provider->chat([new UserMessage('hi')]);

    expect($provider)->toBeInstanceOf(LlamaServerProvider::class)
        ->and($provider->getModel())->toBe('qwen2.5')
        ->and($requests[0]['url'])->toBe('http://127.0.0.1:18089/v1/chat/completions')
        ->and($requests[0]['headers'])->toContain('Authorization: Bearer server-key');
})->with(['llama-cpp', 'llamacpp']);

test('a model llama-server discovers round-trips through fromModelString to the same server', function (?string $providerName) {
    $requests = [];
    $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
        $requests[] = $url;

        return new MockResponse(json_encode(['object' => 'list', 'data' => [['id' => 'qwen.gguf', 'object' => 'model', 'meta' => ['n_ctx' => 4096]]]]), ['http_code' => 200]);
    });
    $config = makeProviderFactoryConfig(['llama-cpp' => ['baseUrl' => 'http://127.0.0.1:18089/v1']]);
    $provider = $providerName === null
        ? new LlamaServerProvider('qwen.gguf', 'http://127.0.0.1:18089/v1', httpClient: $client)
        : ProviderFactory::fromModelString("{$providerName}/qwen.gguf", $config, $client);

    $model = $provider->models()[0];
    $again = ProviderFactory::fromModelString($model->provider . '/' . $model->id, $config, $client);
    $again->models();

    expect($again)->toBeInstanceOf(LlamaServerProvider::class)
        ->and($again->getModel())->toBe('qwen.gguf')
        ->and($requests[1])->toBe('http://127.0.0.1:18089/v1/models');
})->with(['factory llama-cpp' => 'llama-cpp', 'factory llamacpp' => 'llamacpp', 'constructed directly' => null]);

test('llama-cpp without a baseUrl throws an exception that names LlamaServerProvider', function (bool $withRuntime) {
    // 0.20 removed the in-process FFI provider; an injected local runtime no longer makes a provider.
    $runtime = $withRuntime ? new FakeLocalModelRuntime() : null;

    expect(fn() => ProviderFactory::fromModelString('llama-cpp/qwen2.5', null, null, $runtime))
        ->toThrow(\InvalidArgumentException::class, 'llama-cpp provider requires a baseUrl for llama-server (e.g. http://localhost:8080/v1); it makes a LlamaServerProvider. The in-process llama.cpp FFI provider was removed in 0.20.');
})->with(['no runtime' => false, 'runtime injected' => true]);

test('a factory instance with a bound local runtime still needs a baseUrl for llama-cpp', function () {
    $factory = new ProviderFactory(localModelRuntime: new FakeLocalModelRuntime());

    $factory->create('llama-cpp/local-llama');
})->throws(\InvalidArgumentException::class, 'LlamaServerProvider');

test('fromModelString routes anthropic to AnthropicProvider', function () {
    $provider = ProviderFactory::fromModelString('anthropic/claude-sonnet-4-5');

    expect($provider)->toBeInstanceOf(AnthropicProvider::class);
    expect($provider->getModel())->toBe('claude-sonnet-4-5');
});

test('fromModelString routes xai to XAIProvider', function () {
    $provider = ProviderFactory::fromModelString('xai/grok-4');

    expect($provider)->toBeInstanceOf(XAIProvider::class);
    expect($provider->getModel())->toBe('grok-4');
});

test('fromModelString routes openai to OpenAICompatibleProvider', function () {
    $provider = ProviderFactory::fromModelString('openai/gpt-4o');

    expect($provider)->toBeInstanceOf(OpenAICompatibleProvider::class);
    expect($provider->getModel())->toBe('gpt-4o');
});

test('fromModelString routes codex model to OpenAIResponsesProvider', function () {
    $provider = ProviderFactory::fromModelString('openai/gpt-5-codex');

    expect($provider)->toBeInstanceOf(OpenAIResponsesProvider::class);
    expect($provider->getModel())->toBe('gpt-5-codex');
});

test('fromModelString routes unknown provider to OpenAICompatibleProvider', function () {
    $provider = ProviderFactory::fromModelString('custom/my-model');

    expect($provider)->toBeInstanceOf(OpenAICompatibleProvider::class);
    expect($provider->getModel())->toBe('my-model');
});

test('xai provider resolves XAI_API_KEY from environment', function () {
    // Set env var for test
    $previousValue = getenv('XAI_API_KEY');
    putenv('XAI_API_KEY=test-xai-key-12345');

    try {
        $provider = ProviderFactory::fromModelString('xai/grok-4');

        // The provider is created — verify it's the right type
        expect($provider)->toBeInstanceOf(XAIProvider::class);
        expect($provider->getModel())->toBe('grok-4');
    } finally {
        // Restore previous env
        if ($previousValue === false) {
            putenv('XAI_API_KEY');
        } else {
            putenv("XAI_API_KEY={$previousValue}");
        }
    }
});

test('fromModelString routes gemini to GeminiProvider', function () {
    $provider = ProviderFactory::fromModelString('gemini/gemini-2.5-flash');

    expect($provider)->toBeInstanceOf(GeminiProvider::class);
    expect($provider->getModel())->toBe('gemini-2.5-flash');
});

test('fromModelString routes google to GeminiProvider', function () {
    $provider = ProviderFactory::fromModelString('google/gemini-2.5-pro');

    expect($provider)->toBeInstanceOf(GeminiProvider::class);
    expect($provider->getModel())->toBe('gemini-2.5-pro');
});

test('fromModelString routes mistral to MistralProvider', function () {
    $provider = ProviderFactory::fromModelString('mistral/mistral-large-latest');

    expect($provider)->toBeInstanceOf(MistralProvider::class);
    expect($provider->getModel())->toBe('mistral-large-latest');
});

test('fromModelString routes minimax to OpenAICompatibleProvider', function () {
    $provider = ProviderFactory::fromModelString('minimax/minimax-01');

    expect($provider)->toBeInstanceOf(OpenAICompatibleProvider::class);
    expect($provider->getModel())->toBe('minimax-01');
});

test('google alias reuses gemini provider config', function () {
    $capturedUrl = null;
    $mockClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
        $capturedUrl = $url;

        return new MockResponse(json_encode(['models' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    });

    $config = makeProviderFactoryConfig([
        'gemini' => [
            'baseUrl' => 'https://example.test/v1beta',
            'apiKey' => 'gemini-test-key',
        ],
    ]);

    $provider = ProviderFactory::fromModelString('google/gemini-2.5-pro', $config, $mockClient);

    expect($provider)->toBeInstanceOf(GeminiProvider::class);

    $provider->models();

    expect($capturedUrl)->toBe('https://example.test/v1beta/models');
});

test('explicit provider api override routes openai compatible providers to responses api', function () {
    $config = makeProviderFactoryConfig([
        'openai' => [
            'api' => 'openai-responses',
        ],
    ]);

    $provider = ProviderFactory::fromModelString('openai/gpt-4o', $config);

    expect($provider)->toBeInstanceOf(OpenAIResponsesProvider::class);
});

test('gemini provider resolves GEMINI_API_KEY from environment', function () {
    $previousValue = getenv('GEMINI_API_KEY');
    putenv('GEMINI_API_KEY=test-gemini-key');

    try {
        $provider = ProviderFactory::fromModelString('gemini/gemini-2.5-flash');

        expect($provider)->toBeInstanceOf(GeminiProvider::class);
    } finally {
        if ($previousValue === false) {
            putenv('GEMINI_API_KEY');
        } else {
            putenv("GEMINI_API_KEY={$previousValue}");
        }
    }
});

test('mistral provider resolves MISTRAL_API_KEY from environment', function () {
    $previousValue = getenv('MISTRAL_API_KEY');
    putenv('MISTRAL_API_KEY=test-mistral-key');

    try {
        $provider = ProviderFactory::fromModelString('mistral/mistral-large-latest');

        expect($provider)->toBeInstanceOf(MistralProvider::class);
    } finally {
        if ($previousValue === false) {
            putenv('MISTRAL_API_KEY');
        } else {
            putenv("MISTRAL_API_KEY={$previousValue}");
        }
    }
});

test('ollama factory branch applies reasoningEffort from model definition extras', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return new MockResponse(json_encode([
            'model' => 'qwen3:8b',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        ]), ['http_code' => 200]);
    });

    $config = makeProviderFactoryConfig([], [
        'qwen3:8b' => new ModelDefinition(
            id: 'qwen3:8b',
            name: 'Qwen 3',
            provider: 'ollama',
            extras: ['reasoningEffort' => 'low'],
        ),
    ]);

    $provider = ProviderFactory::fromModelString('ollama/qwen3:8b', $config, $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload['reasoning_effort'])->toBe('low');
});

test('ollama factory branch resolves provider-prefixed model definition keys', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return new MockResponse(json_encode([
            'model' => 'qwen3:8b',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        ]), ['http_code' => 200]);
    });

    // OpenClaw-style configs key definitions by "provider/id"
    $config = makeProviderFactoryConfig([], [
        'ollama/qwen3:8b' => new ModelDefinition(
            id: 'qwen3:8b',
            name: 'Qwen 3',
            provider: 'ollama',
            extras: ['reasoningEffort' => 'none'],
        ),
    ]);

    $provider = ProviderFactory::fromModelString('ollama/qwen3:8b', $config, $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload['reasoning_effort'])->toBe('none');
});

test('ollama factory branch ignores invalid reasoningEffort values', function () {
    $requestPayload = null;
    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayload): MockResponse {
        $requestPayload = json_decode($options['body'], true);
        return new MockResponse(json_encode([
            'model' => 'qwen3:8b',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        ]), ['http_code' => 200]);
    });

    $config = makeProviderFactoryConfig([], [
        'qwen3:8b' => new ModelDefinition(
            id: 'qwen3:8b',
            name: 'Qwen 3',
            provider: 'ollama',
            extras: ['reasoningEffort' => 'maximum-overdrive'],
        ),
    ]);

    $provider = ProviderFactory::fromModelString('ollama/qwen3:8b', $config, $mockClient);
    $provider->chat([new UserMessage('hi')]);

    expect($requestPayload)->not->toHaveKey('reasoning_effort');
});
