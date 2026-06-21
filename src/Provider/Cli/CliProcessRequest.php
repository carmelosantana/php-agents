<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Cli;

/**
 * A fully-resolved request to execute a CLI vendor binary.
 *
 * Produced by a CliVendorAdapter and handed to a CliRuntime. The runtime
 * only knows how to spawn a process — all vendor-specific argv/stdin shaping
 * lives in the adapter, keeping the runtime generic across binaries.
 */
final readonly class CliProcessRequest
{
    /**
     * @param list<string> $arguments Argv passed to the binary (excluding the binary itself)
     * @param array<string, string> $env Extra environment variables merged over the inherited env
     */
    public function __construct(
        public string $binary,
        public array $arguments = [],
        public string $stdin = '',
        public array $env = [],
        public ?float $timeout = null,
        public string $model = '',
    ) {}
}
