<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessChunk;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessRequest;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessResult;

/**
 * Host-supplied executor for CLI vendor binaries.
 *
 * php-agents never spawns a process itself — it depends only on symfony/http-client
 * and psr/log. The host application (e.g. Coqui) injects a concrete runtime that
 * knows how to run a binary in its environment. This mirrors how
 * LocalModelRuntimeInterface is injected for the llama.cpp provider, letting the
 * host decide whether execution is blocking or event-loop/Fiber friendly.
 */
interface CliRuntimeInterface
{
    /**
     * Whether the named binary is installed and runnable.
     */
    public function isAvailable(string $binary): bool;

    /**
     * Execute the binary to completion and return its aggregated output.
     */
    public function run(CliProcessRequest $request): CliProcessResult;

    /**
     * Execute the binary, yielding stdout fragments as they arrive.
     *
     * The final chunk MUST have isLast true and carry the process exit code.
     *
     * @return iterable<CliProcessChunk>
     */
    public function stream(CliProcessRequest $request): iterable;
}
