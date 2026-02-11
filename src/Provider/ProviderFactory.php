<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;

final class ProviderFactory
{
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
        $apiKey = $providerConfig['apiKey'] ?? '';

        return match ($providerName) {
            'ollama' => new OllamaProvider(
                model: $model,
                baseUrl: $baseUrl,
            ),
            'anthropic' => new AnthropicProvider(
                model: $model,
                apiKey: is_string($apiKey) ? $apiKey : '',
            ),
            default => new OpenAICompatibleProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: is_string($apiKey) ? $apiKey : '',
            ),
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
            default => '',
        };
    }
}
