<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent\Event;

/**
 * Payload of agent.tool_input_discarded: a call that got agent.tool_input_start will not run.
 * $index and $id are the call's position and id from its ToolInputStart.
 *
 * Reasons:
 * - 'not_tool_use': the response finished with a reason other than ToolUse (max tokens,
 *   refusal), or with Error after the call's arguments completed.
 * - 'invalid_arguments': the response finished with Error and the call's arguments never
 *   completed as a JSON object (no ToolInputEnd).
 * - 'run_limit': a RunLimits limit (bytes, time, tokens) ended the run before the call ran.
 * - 'done_finished_run': a `done` call earlier in the same response finished the run.
 * - 'cancelled': the run was cancelled while the call was streaming.
 * - 'denied', 'invalid_done', 'duplicate_done': a previewed `done` call the policy denied,
 *   whose arguments failed validation, or that was not the first `done` in its response.
 */
final readonly class ToolInputDiscarded
{
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
        public string $reason,
    ) {}
}
