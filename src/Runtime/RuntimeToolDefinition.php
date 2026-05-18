<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

final readonly class RuntimeToolDefinition
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = [],
        public array $metadata = [],
    ) {}
}