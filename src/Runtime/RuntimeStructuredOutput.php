<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

final readonly class RuntimeStructuredOutput
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        public string $name,
        public array $schema,
        public bool $strict = true,
        public string $mode = 'json_schema',
    ) {}
}