<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

/**
 * Token usage for one provider response, normalised across providers.
 *
 * - promptTokens: all input tokens, including cache reads and cache writes.
 * - completionTokens: all output tokens, including reasoning tokens.
 * - totalTokens: the provider's total when it reports one, else prompt + completion.
 * - cacheReadTokens, cacheWriteTokens, reasoningTokens: null when the provider did not
 *   report the figure; 0 when it reported zero.
 */
final readonly class Usage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
        public ?int $reasoningTokens = null,
    ) {}

    /**
     * Sums two usages. A nullable field stays null only when both sides are null.
     */
    public function plus(Usage $other): Usage
    {
        return new Usage(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
            $this->totalTokens + $other->totalTokens,
            self::sumReported($this->cacheReadTokens, $other->cacheReadTokens),
            self::sumReported($this->cacheWriteTokens, $other->cacheWriteTokens),
            self::sumReported($this->reasoningTokens, $other->reasoningTokens),
        );
    }

    private static function sumReported(?int $a, ?int $b): ?int
    {
        if ($a === null && $b === null) {
            return null;
        }

        return ($a ?? 0) + ($b ?? 0);
    }
}
