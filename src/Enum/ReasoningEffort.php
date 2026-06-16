<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Enum;

/**
 * Reasoning effort levels for thinking-capable models.
 *
 * Maps to Ollama's `reasoning_effort` request field on the
 * OpenAI-compatible endpoint, where `none` disables thinking entirely.
 */
enum ReasoningEffort: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case None = 'none';
}
