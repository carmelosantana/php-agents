<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

/**
 * One fragment of reasoning (thinking) text.
 */
final readonly class ReasoningDelta implements StreamEvent
{
    public function __construct(
        public string $delta,
    ) {}
}
