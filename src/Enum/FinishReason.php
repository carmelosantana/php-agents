<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Enum;

enum FinishReason: string
{
    case Stop = 'stop';
    case ToolUse = 'tool_use';
    case MaxTokens = 'max_tokens';
    case MaxIterations = 'max_iterations';
    case Error = 'error';
    case Done = 'done';
    case BudgetExhausted = 'budget_exhausted';
}
