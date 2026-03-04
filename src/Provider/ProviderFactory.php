<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;

/**
 * Creates provider instances from OpenClaw-style model strings.
 *
 * Routes to the correct provider class based on provider name, config,
 * and model auto-detection. Supports explicit API selection via the
 * `api` field in provider config (e.g. `"api": "openai-responses"`).
 */
final class ProviderFactory
{
    /**
     * Conventional environment variable names for provider API keys.
     *
     * Checked via getenv() before falling back to openclaw.json config values.
     * Coqui's CredentialResolver calls putenv() at boot, so workspace .env
     * entries are automatically available here.
     */
    private const ENV_KEY_MAP = [
        'openai' => 'OPENAI_API_KEY',
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
        'xai' => 'XAI_API_KEY',
    ];

    public function __construct(
        private readonly ?ConfigInterface $config = null,
    ) {}

    /**
     * Create a provider from a model string using injected config.
     *
     * Preferred over the static method when you have a factory instance,
     * since the config is already bound and doesn't need to be passed each time.
     */
    public function create(string $modelString): ProviderInterface
    {
        return self::fromModelString($modelString, $this->config);
    }

    /**
     * Create a provider from an OpenClaw-style model string.
     *
     * @param string $modelString e.g., "ollama/llama3.2:latest"
     * @param ConfigInterface|null $config OpenClaw config for baseUrl/apiKey lookups
     */

    public static function fromModelString(
        string $modelString,
        ?ConfigInterface $config = null,
    ): ProviderInterface {
        [$providerName, $model] = self::parseModelString($modelString);

        $providerConfig = $config?->getProviderConfig($providerName) ?? [];
        $baseUrl = self::resolveBaseUrl($providerName, $providerConfig);
        $apiKey = self::resolveApiKey($providerName, $providerConfig);

        $api = $providerConfig['api'] ?? null;

        return match ($providerName) {
            'ollama' => new OllamaProvider(
                model: $model,
                baseUrl: $baseUrl,
            ),
            'anthropic' => new AnthropicProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
            ),
            default => match (true) {
                $api === 'openai-responses' => new OpenAIResponsesProvider(
                    model: $model,
                    baseUrl: $baseUrl,
                    apiKey: $apiKey,
                ),
                self::requiresResponsesApi($model) => new OpenAIResponsesProvider(
                    model: $model,
                    baseUrl: $baseUrl,
                    apiKey: $apiKey,
                ),
                default => new OpenAICompatibleProvider(
                    model: $model,
                    baseUrl: $baseUrl,
                    apiKey: $apiKey,
                ),
            },
        };
    }

    /**
     * Parse "provider/model-name" into [provider, model].
     *
     * @return array{0: string, 1: string}
     */
    public static function parseModelString(string $modelString): array
    {
        $slash = strpos($modelString, '/');

        if ($slash === false) {
            return ['ollama', $modelString];
        }

        return [
            substr($modelString, 0, $slash),
            substr($modelString, $slash + 1),
        ];
    }

    /**
     * Resolve base URL with environment variable overrides.
     *
     * Supports OLLAMA_HOST env var for Docker/container environments.
     *
     * @param array<string, mixed> $providerConfig
     */
    private static function resolveBaseUrl(string $provider, array $providerConfig): string
    {
        if ($provider === 'ollama') {
            $envHost = getenv('OLLAMA_HOST');
            if ($envHost !== false && $envHost !== '') {
                return rtrim($envHost, '/') . '/v1';
            }
        }

        $baseUrl = $providerConfig['baseUrl'] ?? null;

        return is_string($baseUrl) ? $baseUrl : self::defaultBaseUrl($provider);
    }

    private static function defaultBaseUrl(string $provider): string
    {
        return match ($provider) {
            'ollama' => 'http://localhost:11434/v1',
            'openai' => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'xai' => 'https://api.x.ai/v1',
            default => '',
        };
    }

    /**
     * Resolve API key with environment variable override.
     *
     * Priority: getenv(PROVIDER_API_KEY) > config apiKey > empty string.
     * This allows .env files to override hardcoded config values, and
     * enables Coqui's CredentialTool to manage provider keys at runtime.
     *
     * @param array<string, mixed> $providerConfig
     */
    private static function resolveApiKey(string $provider, array $providerConfig): string
    {
        // Check environment variable first (highest priority)
        $envVar = self::ENV_KEY_MAP[$provider] ?? strtoupper($provider) . '_API_KEY';
        $envValue = getenv($envVar);
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }

        // Fall back to config value
        $configKey = $providerConfig['apiKey'] ?? '';

        return is_string($configKey) ? $configKey : '';
    }

    /**
     * Detect models that require the OpenAI Responses API.
     *
     * Codex models (gpt-5-codex, etc.) return 404 on /v1/chat/completions
     * and must be routed through /v1/responses instead.
     */
    private static function requiresResponsesApi(string $model): bool
    {
        return str_contains(strtolower($model), 'codex');
    }
}
