<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

final readonly class Usage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
    ) {}
}
