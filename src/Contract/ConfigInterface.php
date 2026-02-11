<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;

/**
 * Contract for configuration sources.
 */
interface ConfigInterface
{
    /**
     * Get a config value by dot-notation key.
     *
     * @param string $key e.g., "agents.defaults.model.primary"
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Check if a key exists.
     */
    public function has(string $key): bool;

    /**
     * Resolve a model string or alias to a full provider/model identifier.
     */
    public function resolveModel(string $modelOrAlias): string;

    /**
     * Get the primary model string.
     */
    public function getPrimaryModel(): string;

    /**
     * Get fallback model strings, ordered by priority.
     *
     * @return string[]
     */
    public function getFallbacks(): array;

    /**
     * Get the primary vision/image model.
     */
    public function getImageModel(): ?string;

    /**
     * Get provider config (baseUrl, apiKey, etc.) for a given provider name.
     *
     * @return array<string, mixed>
     */
    public function getProviderConfig(string $provider): array;

    /**
     * Get model metadata (contextWindow, maxTokens, capabilities).
     */
    public function getModelDefinition(string $model): ?ModelDefinition;
}
