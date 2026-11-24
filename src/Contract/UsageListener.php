<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Provider\UsageReport;

interface UsageListener
{
    public function onUsage(UsageReport $report): void;
}
