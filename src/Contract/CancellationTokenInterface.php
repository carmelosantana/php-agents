<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Cooperative cancellation token for agent loops.
 *
 * Checked at the top of each iteration in AbstractAgent::run().
 * When cancelled, the agent returns early with a cancellation message
 * instead of making the next LLM call.
 */
interface CancellationTokenInterface
{
    /**
     * Whether cancellation has been requested.
     */
    public function isCancelled(): bool;
}
