<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * Tracks token budget across an agent loop or conversation.
 */
interface ContextWindowInterface
{
    /**
     * Maximum tokens the model supports.
     */
    public function maxTokens(): int;

    /**
     * Tokens reserved for the completion response.
     */
    public function reservedTokens(): int;

    /**
     * Current estimated or reported token usage.
     */
    public function usedTokens(): int;

    /**
     * Remaining tokens available: max - used - reserved.
     */
    public function availableTokens(): int;

    /**
     * Usage as a percentage (0–100).
     */
    public function usagePercent(): float;

    /**
     * Update usage with a pre-flight estimate.
     */
    public function estimate(int $tokens): void;

    /**
     * Update usage with server-reported actual tokens.
     */
    public function report(Usage $usage): void;

    /**
     * Check if usage exceeds a threshold percentage.
     */
    public function exceeds(float $thresholdPercent): bool;

    /**
     * Reset usage tracking.
     */
    public function reset(): void;

    /**
     * Export current state for serialization.
     *
     * @return array{max: int, used: int, reserved: int, available: int, percent: float}
     */
    public function toArray(): array;
}
