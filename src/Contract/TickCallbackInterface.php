<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Called at strategic yielding points in the agent run loop.
 *
 * Consumers can use this to animate status lines, poll for ESC key,
 * or perform any periodic work while the agent is running.
 *
 * Tick points:
 * - Between each stream chunk from the provider
 * - Between each tool call execution
 * - After the provider call completes (before tool processing)
 */
interface TickCallbackInterface
{
    public function tick(): void;
}
