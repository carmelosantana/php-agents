<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent\Event;

use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * Payload of agent.finish, fired once just before run() returns, from the Output it returns:
 * its finish reason, summed usage, iteration count and model. The model is redacted and cut to
 * 128 characters (UntrustedText::forDisplay()); Output::$model keeps it verbatim.
 */
final readonly class RunFinish
{
    public function __construct(
        public AgentFinishReason $reason,
        public Usage $usage,
        public int $iterations,
        public string $model,
    ) {}
}
