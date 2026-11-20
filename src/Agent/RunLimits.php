<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Provider\RequestLimits;

/**
 * Limits on one AbstractAgent::run().
 *
 * - $maxToolCallsPerTurn caps the tool calls kept from one provider response, counted as they
 *   stream (denied and unknown calls count). A call that opens beyond it is discarded at once
 *   (agent.tool_input_discarded 'tool_call_limit'), never kept, shown to the policy or run, and
 *   gets no result. When the response's kept calls run, agent.warning fires once after their
 *   results; a response that ends the run instead (a done call, a finish other than ToolUse, a
 *   cancel or limit) fires no warning, and the discards are the only signal.
 * - $maxToolCallsPerRun caps the tool calls sent to the policy and executor across the run. Calls
 *   beyond it get an error result, and the run then ends as AgentFinishReason::BudgetExhausted.
 * - $executeUnadvertisedTools lets the model execute any registered tool, including the ones
 *   maxTools left out of the provider payload. By default only advertised tools and tools a
 *   ToolGrantInterface tool granted can run.
 * - $maxRunSeconds caps the wall time of the run, measured with hrtime() from the start of run().
 * - $maxResponseBytes caps the bytes received from the provider across the run, counting every
 *   field the agent keeps: text, reasoning and tool-input deltas; tool call ids and names; the
 *   JSON of each call's final arguments beyond its streamed deltas, and of its metadata; and each
 *   finish's raw reason, model and content-block JSON, less the thinking, text and tool input
 *   those blocks repeat from what the response streamed. It defaults to DEFAULT_MAX_RESPONSE_BYTES,
 *   one request's worth (RequestLimits::DEFAULT_MAX_RESPONSE_BYTES, 32 MiB), so a hostile endpoint
 *   cannot fill memory with many tool rounds that each fit the per-request cap. Pass 0 to opt out;
 *   raise it with RequestLimits::$maxResponseBytes if one response may legitimately be larger.
 * - $maxEstimatedTokens caps the run's local token estimate: each request's estimate (conversation
 *   plus tool schemas) plus each response's max(reported completion tokens, bytes / 4). It is
 *   also checked on every stream event, and does not trust the usage the provider reports.
 * - $maxInputTokens caps the estimated size of the new input message and of each pending input;
 *   a larger one never reaches the provider.
 *
 * Each of the four ends the run as AgentFinishReason::BudgetExhausted. They are cooperative: the
 * agent checks them on every stream event, at each iteration and before each tool or batch call.
 *
 * 0 means unlimited, and every limit but $maxResponseBytes defaults to 0. The first done call of a response counts toward neither tool-call cap; a
 * later done call in that response counts toward the per-turn cap. Any negative value throws
 * \InvalidArgumentException.
 */
final readonly class RunLimits
{
    /** Default $maxResponseBytes: one request's worth of response bytes across the whole run. */
    public const int DEFAULT_MAX_RESPONSE_BYTES = RequestLimits::DEFAULT_MAX_RESPONSE_BYTES;

    public function __construct(
        public int $maxToolCallsPerTurn = 0,
        public int $maxToolCallsPerRun = 0,
        public bool $executeUnadvertisedTools = false,
        public float $maxRunSeconds = 0.0,
        public int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        public int $maxEstimatedTokens = 0,
        public int $maxInputTokens = 0,
    ) {
        $limits = [
            'maxToolCallsPerTurn' => $maxToolCallsPerTurn,
            'maxToolCallsPerRun' => $maxToolCallsPerRun,
            'maxRunSeconds' => $maxRunSeconds,
            'maxResponseBytes' => $maxResponseBytes,
            'maxEstimatedTokens' => $maxEstimatedTokens,
            'maxInputTokens' => $maxInputTokens,
        ];
        foreach ($limits as $name => $value) {
            if (!($value >= 0)) {
                throw new \InvalidArgumentException(sprintf('RunLimits $%s must not be negative, got %s.', $name, $value));
            }
        }
    }
}
