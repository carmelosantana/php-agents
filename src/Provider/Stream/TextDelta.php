<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

/**
 * One fragment of response text.
 */
final readonly class TextDelta implements StreamEvent
{
    public function __construct(
        public string $delta,
    ) {}
}
