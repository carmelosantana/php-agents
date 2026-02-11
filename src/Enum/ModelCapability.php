<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Enum;

enum ModelCapability: string
{
    case Text = 'text';
    case Image = 'image';
    case Reasoning = 'reasoning';
    case Code = 'code';
    case Tools = 'tools';
}
