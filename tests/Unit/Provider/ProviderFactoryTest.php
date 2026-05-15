<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaCppProvider;
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

test('fromModelString routes llama-cpp to LlamaCppProvider when a runtime is injected', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(id: 'local-llama', name: 'Local Llama'));

    $provider = ProviderFactory::fromModelString('llama-cpp/local-llama', null, null, $runtime);

    expect($provider)->toBeInstanceOf(LlamaCppProvider::class)
        ->and($provider->getModel())->toBe('local-llama');
});

test('factory instance uses bound local runtime for llama-cpp models', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(id: 'local-llama', name: 'Local Llama'));

    $factory = new ProviderFactory(localModelRuntime: $runtime);
    $provider = $factory->create('llama-cpp/local-llama');

    expect($provider)->toBeInstanceOf(LlamaCppProvider::class)
        ->and($provider->getModel())->toBe('local-llama');
});

test('llama-cpp factory branch applies numCtx from model definition when present', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(id: 'local-llama', name: 'Local Llama', defaultTemplate: 'baseline'));

    $config = makeProviderFactoryConfig([], [
        'local-llama' => new ModelDefinition(
            id: 'local-llama',
            name: 'Local Llama',
            provider: 'llama-cpp',
            numCtx: 16384,
        ),
    ]);

    $provider = ProviderFactory::fromModelString('llama-cpp/local-llama', $config, null, $runtime);

    expect($provider)->toBeInstanceOf(LlamaCppProvider::class);
});

test('llama-cpp factory forwards template and parser overrides from config into provider execution', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'factory-local', name: 'Factory Local', defaultTemplate: 'raw'),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ok')]]],
    );

    $config = makeProviderFactoryConfig([
        'llama-cpp' => [
            'defaultTemplate' => 'chatml',
            'defaultToolParser' => 'json',
        ],
    ], [
        'factory-local' => new ModelDefinition(
            id: 'factory-local',
            name: 'Factory Local',
            provider: 'llama-cpp',
            extras: [
                'template' => 'baseline',
                'toolParser' => 'native',
            ],
        ),
    ]);

    $provider = ProviderFactory::fromModelString('llama-cpp/factory-local', $config, null, $runtime);
    $provider->chat([new UserMessage('Factory prompt')]);

    expect($runtime->lastRequest()?->prompt)->toBe(implode("\n\n", [
        'USER: Factory prompt',
        'ASSISTANT:',
    ]))
        ->and($runtime->lastRequest()?->options['template'])->toBe('baseline')
        ->and($runtime->lastRequest()?->options)->not->toHaveKey('toolParser');
});

test('llama-cpp factory forwards multimodal and structured output config overrides', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'factory-vision-structured',
            name: 'Factory Vision Structured',
            defaultTemplate: 'baseline',
            supportsVision: true,
        ),
        [
            'supportsStructuredOutput' => true,
            'responses' => ['*' => [new RuntimeCompletionChunk(content: '{}')]],
        ],
    );

    $config = makeProviderFactoryConfig([
        'llama-cpp' => [
            'defaultTemplate' => 'baseline',
        ],
    ], [
        'factory-vision-structured' => new ModelDefinition(
            id: 'factory-vision-structured',
            name: 'Factory Vision Structured',
            provider: 'llama-cpp',
            extras: [
                'projectorPath' => '/models/factory.mmproj',
                'maxImages' => 2,
                'imageTokenCost' => 256,
                'structuredOutputModes' => ['json_schema'],
                'supportsStructuredOutput' => true,
            ],
        ),
    ]);

    $provider = ProviderFactory::fromModelString('llama-cpp/factory-vision-structured', $config, null, $runtime);
    $provider->structured(
        [new UserMessage('Return empty object')],
        json_encode(['name' => 'empty', 'schema' => ['type' => 'object']], JSON_THROW_ON_ERROR),
    );

    expect($runtime->lastRequest()?->structuredOutput?->strict)->toBeTrue();

    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'See'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('img')]],
        ]),
    ]);

    expect($runtime->lastRequest()?->options['projectorPath'])->toBe('/models/factory.mmproj')
        ->and($runtime->lastRequest()?->options['imageTokenEstimate'])->toBe(256);
});

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
