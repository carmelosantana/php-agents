<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\BudgetPruningStrategyInterface;

/**
 * Minimal pruning strategy — trims tool results and repairs structural integrity
 * without dropping any conversation turns.
 *
 * Use this when Coqui's pre-turn summarization handles conversation size management
 * and the per-iteration safety net should only clean up oversized tool results.
 */
final class SafetyOnlyPruningStrategy implements BudgetPruningStrategyInterface
{
    public function prune(Conversation $conversation, int $budgetTokens): Conversation
    {
        $result = clone $conversation;

        $currentTokens = $result->estimateTokens();

        // Soft-trim oversized tool results (preserve 500 chars, ellipsis at 100)
        if ($currentTokens > $budgetTokens) {
            $result = $result->trimToolResults(500, 100);
            $currentTokens = $result->estimateTokens();
        }

        // More aggressive trim if still over budget
        if ($currentTokens > $budgetTokens) {
            $result = $result->trimToolResults(200, 50);
        }

        // Repair any orphaned tool results
        $result = $result->repairToolPairing();

        // Merge consecutive same-role messages
        $result = $result->mergeConsecutiveRoles();

        return $result;
    }
}
