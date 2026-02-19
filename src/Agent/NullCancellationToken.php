<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;

/**
 * No-op cancellation token that is never cancelled.
 *
 * Used as the default when no external cancellation is needed.
 */
final class NullCancellationToken implements CancellationTokenInterface
{
    public function isCancelled(): bool
    {
        return false;
    }
}
