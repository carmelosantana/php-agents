<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

/**
 * Limits on one AbstractAgent::run().
 *
 * - $maxToolCallsPerTurn caps the tool calls from one provider response that are sent to the
 *   execution policy (denied and unknown calls count). Calls beyond it are not shown to the
 *   policy or run; each gets an error result.
 * - $maxToolCallsPerRun caps the tool calls sent to the policy and executor across the run. Calls
 *   beyond it get an error result, and the run then ends as AgentFinishReason::BudgetExhausted.
 * - $executeUnadvertisedTools lets the model execute any registered tool, including the ones
 *   maxTools left out of the provider payload. By default only advertised tools and tools a
 *   ToolGrantInterface tool granted can run.
 * - $maxRunSeconds caps the wall time of the run, measured with hrtime() from the start of run().
 * - $maxResponseBytes caps the bytes received from the provider across the run: the text of
 *   every text, reasoning and tool-input delta, plus the JSON of each response's content blocks.
 * - $maxEstimatedTokens caps the run's local token estimate: each request's estimate (conversation
 *   plus tool schemas) plus each response's max(reported completion tokens, bytes / 4). It is
 *   also checked on every stream event, and does not trust the usage the provider reports.
 * - $maxInputTokens caps the estimated size of the new input message and of each pending input;
 *   a larger one never reaches the provider.
 *
 * Each of the four ends the run as AgentFinishReason::BudgetExhausted. They are cooperative: the
 * agent checks them on every stream event, at each iteration and before each tool or batch call.
 *
 * 0 means unlimited. The done tool counts toward neither tool-call cap. Any negative value throws
 * \InvalidArgumentException.
 */
final readonly class RunLimits
{
    public function __construct(
        public int $maxToolCallsPerTurn = 0,
        public int $maxToolCallsPerRun = 0,
        public bool $executeUnadvertisedTools = false,
        public float $maxRunSeconds = 0.0,
        public int $maxResponseBytes = 0,
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
