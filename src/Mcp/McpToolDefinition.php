<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

final readonly class McpToolDefinition
{
    /**
     * @param array<array-key, mixed> $inputSchema
     * @param array<array-key, mixed> $annotations
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $annotations = [],
        public ?string $title = null,
    ) {}
}
