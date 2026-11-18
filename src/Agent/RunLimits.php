<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

/**
 * Limits on one AbstractAgent::run().
 *
 * - $maxToolCallsPerTurn caps the tool calls executed from one provider response. Calls beyond it
 *   are not shown to the execution policy or run; each gets an error result.
 * - $maxToolCallsPerRun caps the tool calls sent to the policy and executor across the run. Calls
 *   beyond it get an error result, and the run then ends as AgentFinishReason::BudgetExhausted.
 * - $executeUnadvertisedTools lets the model execute any registered tool, including the ones
 *   maxTools left out of the provider payload. By default only advertised tools and tools a
 *   ToolGrantInterface tool granted can run.
 *
 * 0 means unlimited. The done tool counts toward neither cap. Any negative value throws
 * \InvalidArgumentException.
 */
final readonly class RunLimits
{
    public function __construct(
        public int $maxToolCallsPerTurn = 0,
        public int $maxToolCallsPerRun = 0,
        public bool $executeUnadvertisedTools = false,
    ) {
        foreach (['maxToolCallsPerTurn' => $maxToolCallsPerTurn, 'maxToolCallsPerRun' => $maxToolCallsPerRun] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('RunLimits $%s must not be negative, got %d.', $name, $value));
            }
        }
    }
}
