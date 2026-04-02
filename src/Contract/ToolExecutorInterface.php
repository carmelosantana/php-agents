<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Controls HOW a tool is invoked during the agent run loop.
 *
 * The default SynchronousToolExecutor calls tool->execute() inline.
 * Consumers (e.g. Coqui) can inject async implementations that wrap
 * execution in an event loop, enabling non-blocking tool calls.
 */
interface ToolExecutorInterface
{
    /**
     * Execute a tool with the given arguments.
     *
     * The implementation decides whether this is synchronous, async,
     * wrapped in a Fiber, or run in a subprocess — AbstractAgent
     * only cares about the returned ToolResult.
     */
    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(ToolInterface $tool, array $arguments): ToolResult;
}
