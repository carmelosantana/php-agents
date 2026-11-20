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
 * - 'provider_error': the stream threw, or ended without a Finish, after the call started.
 * - 'done_finished_run': a `done` call earlier in the same response finished the run.
 * - 'cancelled': a cancel abandoned the response before it finished, so none of its calls
 *   runs, including a call whose arguments had completed and a previewed `done` call. Calls of
 *   a finished response that a cancel stops get a 'cancelled' tool result instead.
 * - 'denied', 'invalid_done': the first `done` call of a response that the execution policy
 *   denied, or whose arguments failed validation; the run continues.
 * - 'duplicate_done': a `done` call that was not the first in its response.
 * - 'tool_call_limit': the call opened past RunLimits::$maxToolCallsPerTurn. It fires right after
 *   the call's agent.tool_input_start (or, for a call whose end came without a start, in place of
 *   its agent.tool_input_end), and the call's later input events are not forwarded.
 * - 'tool_call_overflow': the response opened more than AbstractAgent::MAX_TOOL_CALLS_PER_RESPONSE
 *   (1024) calls. Fired once, for the first call past it, in place of its agent.tool_input_start
 *   (or end); no event of any call past it is forwarded or kept.
 *
 * For a `done` call this retracts what agent.done_delta previewed.
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
