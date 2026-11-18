<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

final readonly class Response
{
    /**
     * @param ToolCall[] $toolCalls
     * @param list<array<string, mixed>> $contentBlocks Provider-native assistant content blocks, in order
     *        (Anthropic thinking, redacted_thinking, text, tool_use). Nested JSON objects are stdClass
     *        and lists are PHP lists, so json_encode gives back the original structure.
     */
    public function __construct(
        public string $content,
        public ProviderFinishReason $finishReason,
        public array $toolCalls = [],
        public string $model = '',
        public ?Usage $usage = null,
        public string $reasoning = '',
        public array $contentBlocks = [],
    ) {}
}
