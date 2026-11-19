<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent\Event;

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * Payload of agent.step_finish: one provider response completed. $iteration is 1-based;
 * the other fields come from the stream's Finish event.
 */
final readonly class StepFinish
{
    public function __construct(
        public int $iteration,
        public ProviderFinishReason $reason,
        public string $rawReason,
        public ?Usage $usage,
        public string $model,
    ) {}
}
