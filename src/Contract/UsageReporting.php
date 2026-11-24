<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

interface UsageReporting
{
    /** Return a copy whose later inference requests report to $listener. */
    public function withUsageListener(UsageListener $listener): static;
}
