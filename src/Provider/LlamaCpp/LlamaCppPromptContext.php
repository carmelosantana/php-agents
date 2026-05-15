<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

final readonly class LlamaCppPromptContext
{
    public function __construct(
        public string $prompt,
        public string $template,
        public ?string $toolParser = null,
    ) {}
}