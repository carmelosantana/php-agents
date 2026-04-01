<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;

/**
 * No-op tick callback — does nothing on each tick.
 *
 * Used as the default when no custom tick callback is injected.
 */
final readonly class NullTickCallback implements TickCallbackInterface
{
    #[\Override]
    public function tick(): void
    {
        // No-op
    }
}
