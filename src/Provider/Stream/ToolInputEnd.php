<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

use CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * A tool call's arguments are complete and decoded to a JSON object.
 */
final readonly class ToolInputEnd implements StreamEvent
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $metadata Provider-specific metadata (e.g. Gemini thoughtSignature)
     */
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
        public array $arguments,
        public array $metadata = [],
    ) {}

    public function toToolCall(): ToolCall
    {
        return new ToolCall($this->id, $this->name, $this->arguments, $this->metadata);
    }
}
