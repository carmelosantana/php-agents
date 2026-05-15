<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput;

final readonly class LlamaCppMultimodalContext
{
    /**
     * @param RuntimeImageInput[] $images
     * @param array<string, mixed> $requestOptions
     */
    public function __construct(
        public array $images,
        public array $requestOptions,
    ) {}
}