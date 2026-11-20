<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

/**
 * A raw fragment of a tool call's argument JSON.
 */
final readonly class ToolInputDelta implements StreamEvent
{
    public function __construct(
        public int $index,
        public string $id,
        public string $delta,
    ) {}
}
