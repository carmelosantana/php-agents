<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Cli;

/**
 * A single fragment of CLI stdout emitted while a streaming process runs.
 *
 * Mirrors the chunk shape Coqui's ReactResponseStream produces for HTTP SSE,
 * so a runtime can resolve one chunk per stdout 'data' event and keep the
 * event loop (and spinner) alive between fragments.
 */
final readonly class CliProcessChunk
{
    public function __construct(
        public string $content = '',
        public bool $isLast = false,
        public int $exitCode = 0,
        public ?string $error = null,
    ) {}
}
