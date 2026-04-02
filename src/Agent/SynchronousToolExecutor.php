<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Default synchronous tool executor — calls tool->execute() inline.
 *
 * This is the extracted current behavior from AbstractAgent::run().
 * Used as the default when no custom executor is injected.
 *
 * Does NOT implement BatchToolExecutorInterface — that interface signals
 * concurrent execution capability (e.g. ConcurrentToolExecutor with Fibers).
 * When this executor is active, AbstractAgent uses the serial path with
 * cancellation checks between each tool call.
 */
final readonly class SynchronousToolExecutor implements ToolExecutorInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    #[\Override]
    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        return $tool->execute($arguments);
    }
}
