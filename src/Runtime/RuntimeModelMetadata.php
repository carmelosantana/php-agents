<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

final readonly class RuntimeModelMetadata
{
    /**
     * @param string[] $aliases
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $path = '',
        public ?string $family = null,
        public int $contextWindow = 4096,
        public int $maxTokens = 2048,
        public bool $supportsTools = false,
        public bool $supportsVision = false,
        public bool $supportsReasoning = false,
        public bool $supportsThinking = false,
        public ?string $projectorPath = null,
        public ?string $defaultTemplate = null,
        public ?string $defaultToolParser = null,
        public array $aliases = [],
        public array $extras = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'family' => $this->family,
            'contextWindow' => $this->contextWindow,
            'maxTokens' => $this->maxTokens,
            'supportsTools' => $this->supportsTools,
            'supportsVision' => $this->supportsVision,
            'supportsReasoning' => $this->supportsReasoning,
            'supportsThinking' => $this->supportsThinking,
            'projectorPath' => $this->projectorPath,
            'defaultTemplate' => $this->defaultTemplate,
            'defaultToolParser' => $this->defaultToolParser,
            'aliases' => $this->aliases,
            ...$this->extras,
        ];
    }
}