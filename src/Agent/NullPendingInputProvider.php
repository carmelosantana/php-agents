<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;

/**
 * No-op implementation that never provides any pending input.
 *
 * Used as the default when no external input injection is needed.
 */
final class NullPendingInputProvider implements PendingInputProviderInterface
{
    public function consumePendingInputs(): array
    {
        return [];
    }
}
