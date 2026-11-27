<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

/**
 * One fragment of a structured-output JSON document. The fragments, concatenated, are the document.
 */
final readonly class StructuredDelta implements StreamEvent
{
    public function __construct(
        public string $delta,
    ) {}
}
