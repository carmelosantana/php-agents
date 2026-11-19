<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Cooperative cancellation token for agent loops.
 *
 * AbstractAgent::run() checks it at the top of each iteration, on every stream event (ticks
 * included), before each tool call and before a batch starts. A cancel ends the run with
 * AgentFinishReason::Cancelled, after agent.error 'Task cancelled':
 * - mid-stream, the stream is abandoned and its text so far is the content (else
 *   'Task was cancelled.'); no call of that response runs;
 * - during tools, finished calls keep their results and every call that did not start gets
 *   an error result 'Cancelled before it ran.' with error code 'cancelled'. A running batch
 *   or tool is not interrupted.
 */
interface CancellationTokenInterface
{
    /**
     * Whether cancellation has been requested.
     */
    public function isCancelled(): bool;
}
