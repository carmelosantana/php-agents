<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Message\Conversation;

/**
 * Strategy for pruning a conversation to fit within a token budget.
 *
 * Implementations decide how to reduce conversation size — the default
 * strategy trims tool results and drops oldest turns. Custom strategies
 * can summarize, compress, or apply any domain-specific pruning logic.
 */
interface BudgetPruningStrategyInterface
{
    /**
     * Prune the conversation to fit within the given token budget.
     *
     * Must return a new Conversation instance (immutable contract).
     *
     * @param Conversation $conversation The conversation to prune.
     * @param int $budgetTokens The effective token budget (already accounts for safety margin).
     */
    public function prune(Conversation $conversation, int $budgetTokens): Conversation;
}
