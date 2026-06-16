<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Enum;

enum AgentFinishReason: string
{
    case Stop = 'stop';
    case MaxIterations = 'max_iterations';
    case Error = 'error';
    case Done = 'done';
    case BudgetExhausted = 'budget_exhausted';
    case EmptyResponse = 'empty_response';
}