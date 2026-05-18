<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

final readonly class RuntimeImageInput
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $mimeType,
        public string $bytes,
        public array $metadata = [],
    ) {}
}