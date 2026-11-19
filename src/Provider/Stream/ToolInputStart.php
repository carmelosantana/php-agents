<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

/**
 * A tool call opens. $index is its 0-based position in this response; $id is the provider's call id.
 */
final readonly class ToolInputStart implements StreamEvent
{
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
    ) {}
}
