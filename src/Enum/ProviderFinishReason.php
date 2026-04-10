<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Enum;

enum ProviderFinishReason: string
{
    case Stop = 'stop';
    case ToolUse = 'tool_use';
    case MaxTokens = 'max_tokens';
    case Error = 'error';
}