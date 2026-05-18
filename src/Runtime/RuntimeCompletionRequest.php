<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

final readonly class RuntimeCompletionRequest
{
    /**
     * @param RuntimeImageInput[] $images
     * @param RuntimeToolDefinition[] $tools
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $prompt,
        public array $images = [],
        public array $tools = [],
        public ?string $sequenceId = null,
        public ?RuntimeStructuredOutput $structuredOutput = null,
        public array $options = [],
    ) {}
}