<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Config;

use CarmeloSantana\PHPAgents\Enum\ModelCapability;

final readonly class ModelDefinition
{
    /**
     * @param ModelCapability[] $capabilities
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $provider,
        public array $capabilities = [ModelCapability::Text],
        public bool $reasoning = false,
        public int $contextWindow = 4096,
        public int $maxTokens = 2048,
        public ?string $alias = null,
    ) {}

    public function supports(ModelCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * Build from OpenClaw config model entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromOpenClaw(string $provider, array $data): self
    {
        $capabilities = array_map(
            fn(string $input) => ModelCapability::from($input),
            $data['input'] ?? ['text'],
        );

        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? $data['id'] ?? '',
            provider: $provider,
            capabilities: $capabilities,
            reasoning: $data['reasoning'] ?? false,
            contextWindow: $data['contextWindow'] ?? 4096,
            maxTokens: $data['maxTokens'] ?? 2048,
            alias: $data['alias'] ?? null,
        );
    }
}
