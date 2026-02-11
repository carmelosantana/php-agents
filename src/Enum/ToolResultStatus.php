<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Enum;

enum ToolResultStatus: string
{
    case Success = 'success';
    case Error = 'error';
    case Timeout = 'timeout';
}
