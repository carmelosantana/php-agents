<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\BudgetPruningStrategyInterface;
use CarmeloSantana\PHPAgents\Enum\Role;

/**
 * Default budget pruning strategy — trims tool results, drops oldest turns,
 * repairs tool pairing, and merges consecutive roles.
 *
 * This is the exact logic previously hardcoded in Conversation::fitWithinBudget().
 * Extracted into a strategy class for pluggability while preserving zero behavior
 * change for existing users.
 */
final class DefaultBudgetPruningStrategy implements BudgetPruningStrategyInterface
{
    public function prune(Conversation $conversation, int $budgetTokens): Conversation
    {
        $result = clone $conversation;

        // Step 1: Soft-trim tool results if over budget
        if ($result->estimateTokens() > $budgetTokens) {
            $result = $result->trimToolResults(500, 100);
        }

        // Step 2: Progressively drop oldest turns if still over budget
        $userCount = count($result->filter(Role::User));
        while ($result->estimateTokens() > $budgetTokens && $userCount > 1) {
            $userCount--;
            $result = $result->dropOldestTurns($userCount);
        }

        // Step 3: More aggressive tool trimming if still over budget
        if ($result->estimateTokens() > $budgetTokens) {
            $result = $result->trimToolResults(200, 50);
        }

        // Step 4: Repair any orphaned tool results from dropped turns
        $result = $result->repairToolPairing();

        // Step 5: Merge consecutive same-role messages that may result from pruning
        $result = $result->mergeConsecutiveRoles();

        return $result;
    }
}
