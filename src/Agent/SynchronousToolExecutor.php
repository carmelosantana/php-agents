<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Exception\TerminationException;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Default synchronous tool executor — calls tool->execute() inline.
 *
 * This is the extracted current behavior from AbstractAgent::run().
 * Used as the default when no custom executor is injected.
 *
 * Also implements BatchToolExecutorInterface with a serial loop,
 * ensuring the batch contract is testable without async infrastructure.
 */
final readonly class SynchronousToolExecutor implements BatchToolExecutorInterface
{
    #[\Override]
    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        return $tool->execute($arguments);
    }

    #[\Override]
    public function executeBatch(array $batch): array
    {
        $results = [];
        $terminationException = null;

        foreach ($batch as $i => $entry) {
            if ($terminationException !== null) {
                $results[$i] = ToolResult::error('Cancelled: another tool requested termination');
                continue;
            }

            try {
                $results[$i] = $entry['tool']->execute($entry['arguments']);
            } catch (TerminationException $e) {
                $results[$i] = ToolResult::success($e->getMessage());
                $terminationException = $e;
            } catch (\Throwable $e) {
                $results[$i] = ToolResult::error($e->getMessage());
            }
        }

        if ($terminationException !== null) {
            throw $terminationException;
        }

        return $results;
    }
}
