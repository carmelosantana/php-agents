<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Context;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CarmeloSantana\PHPAgents\Provider\Usage;

final class ContextWindow implements ContextWindowInterface
{
    private int $usedTokens = 0;

    public function __construct(
        private readonly int $maxTok = 128000,
        private readonly int $reservedTok = 4096,
        private readonly float $warningThreshold = 80.0,
        private readonly float $criticalThreshold = 95.0,
    ) {}

    public static function fromModel(ModelDefinition $model): self
    {
        return new self(
            maxTok: $model->contextWindow,
            reservedTok: $model->maxTokens,
        );
    }

    public function maxTokens(): int
    {
        return $this->maxTok;
    }

    public function reservedTokens(): int
    {
        return $this->reservedTok;
    }

    public function usedTokens(): int
    {
        return $this->usedTokens;
    }

    public function availableTokens(): int
    {
        return max(0, $this->maxTok - $this->usedTokens - $this->reservedTok);
    }

    public function usagePercent(): float
    {
        $effective = $this->maxTok - $this->reservedTok;

        return $effective > 0 ? round(($this->usedTokens / $effective) * 100, 1) : 100.0;
    }

    public function estimate(int $tokens): void
    {
        $this->usedTokens = $tokens;
    }

    public function report(Usage $usage): void
    {
        $this->usedTokens = $usage->totalTokens;
    }

    public function exceeds(float $thresholdPercent): bool
    {
        return $this->usagePercent() >= $thresholdPercent;
    }

    public function reset(): void
    {
        $this->usedTokens = 0;
    }

    public function toArray(): array
    {
        return [
            'max' => $this->maxTok,
            'used' => $this->usedTokens,
            'reserved' => $this->reservedTok,
            'available' => $this->availableTokens(),
            'percent' => $this->usagePercent(),
        ];
    }

    public function isWarning(): bool
    {
        return $this->exceeds($this->warningThreshold);
    }

    public function isCritical(): bool
    {
        return $this->exceeds($this->criticalThreshold);
    }
}
