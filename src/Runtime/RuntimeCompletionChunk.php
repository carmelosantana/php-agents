<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

final readonly class RuntimeCompletionChunk
{
    /**
     * @param ToolCall[] $toolCalls
     * @param array<string, mixed> $metadata
     * @param string[] $warnings
     */
    public function __construct(
        public string $content = '',
        public string $reasoning = '',
        public array $toolCalls = [],
        public ?RuntimeFinishReason $finishReason = null,
        public ?Usage $usage = null,
        public array $metadata = [],
        public array $warnings = [],
    ) {}
}