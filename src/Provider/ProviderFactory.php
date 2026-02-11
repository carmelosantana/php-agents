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
        $baseUrl = $providerConfig['baseUrl'] ?? self::defaultBaseUrl($providerName);
        $apiKey = $providerConfig['apiKey'] ?? '';

        return match ($providerName) {
            'ollama' => new OllamaProvider(
                model: $model,
                baseUrl: is_string($baseUrl) ? $baseUrl : 'http://localhost:11434/v1',
            ),
            'anthropic' => new AnthropicProvider(
                model: $model,
                apiKey: is_string($apiKey) ? $apiKey : '',
            ),
            default => new OpenAICompatibleProvider(
                model: $model,
                baseUrl: is_string($baseUrl) ? $baseUrl : '',
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
