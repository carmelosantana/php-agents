<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\UsageListener;

final class CompositeUsageListener implements UsageListener
{
    /** @var array<array-key, UsageListener> */
    private readonly array $listeners;

    public function __construct(UsageListener ...$listeners)
    {
        $this->listeners = $listeners;
    }

    public function onUsage(UsageReport $report): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener->onUsage($report);
            } catch (\Throwable) {
                // Each child receives the report independently.
            }
        }
    }
}
