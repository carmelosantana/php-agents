<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeStructuredOutput;

final readonly class LlamaCppStructuredOutputContext
{
    public function __construct(
        public ?RuntimeStructuredOutput $runtimeStructuredOutput,
        public ?string $promptAppendix = null,
        public bool $bestEffort = false,
    ) {}
}