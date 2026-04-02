<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Extends ToolExecutorInterface with batch execution support.
 *
 * When AbstractAgent detects that the injected executor implements this
 * interface AND the provider returned multiple tool calls, it delegates
 * to executeBatch() instead of looping execute() serially.
 *
 * This interface signals concurrent execution capability. Only executors
 * that can meaningfully parallelize I/O should implement it:
 * - ConcurrentToolExecutor (Coqui): Fiber-per-tool via ReactPHP
 *
 * The default SynchronousToolExecutor does NOT implement this interface.
 * When no batch executor is injected, AbstractAgent uses the serial path
 * with cancellation checks between each tool call.
 *
 * Results MUST be returned in the same order as the input batch,
 * regardless of completion order. This preserves conversation history
 * ordering for all providers (OpenAI, Anthropic, Gemini).
 */
interface BatchToolExecutorInterface extends ToolExecutorInterface
{
    /**
     * Execute multiple tools, potentially concurrently.
     *
     * Each entry in $batch is an associative array with:
     *   - 'tool': ToolInterface — the resolved tool instance
     *   - 'arguments': array<string, mixed> — the tool call arguments
     *
     * Returns ToolResult[] in the same positional order as $batch.
     *
     * If any tool throws a TerminationException, the executor MUST:
     *   1. Cancel/resolve remaining tools (implementation-specific)
     *   2. Return partial results for completed tools
     *   3. Re-throw the TerminationException
     *
     * All other exceptions per-tool are caught and returned as
     * ToolResult::error() in the corresponding position.
     *
     * @param list<array{tool: ToolInterface, arguments: array<string, mixed>}> $batch
     * @return ToolResult[]
     * @throws \CarmeloSantana\PHPAgents\Exception\TerminationException
     */
    public function executeBatch(array $batch): array;
}
