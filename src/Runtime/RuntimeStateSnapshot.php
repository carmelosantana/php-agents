<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

final readonly class RuntimeStateSnapshot
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $bytes,
        public ?string $sequenceId = null,
        public array $metadata = [],
    ) {}
}