<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Cli;

/**
 * The aggregated outcome of a non-streaming CLI execution.
 */
final readonly class CliProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr = '',
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }
}
