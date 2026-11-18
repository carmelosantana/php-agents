<?php

/**
 * Small helpers shared by the examples. Kept in one file so tests/Unit/Examples can pin them.
 */

declare(strict_types=1);

namespace PHPAgentsExamples;

use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * A tool that reads one fixed file. It takes no parameters, so the model cannot pick a path.
 */
function readmeTool(string $readmePath): Tool
{
    return new Tool(
        name: 'read_readme',
        description: 'Read the project README.md. Takes no arguments.',
        parameters: [],
        callback: static function () use ($readmePath): ToolResult {
            $content = is_file($readmePath) ? file_get_contents($readmePath) : false;

            return $content === false
                ? ToolResult::error('README.md could not be read.')
                : ToolResult::success($content);
        },
    );
}

/**
 * Accept a model name from request input only when it is a plain identifier.
 */
function safeModel(mixed $model, string $default): string
{
    return is_string($model) && preg_match('/^[A-Za-z0-9._:\/-]{1,64}$/D', $model) === 1
        ? $model
        : $default;
}

/**
 * A user-facing message for a run that did not finish cleanly, or null when it did.
 */
function finishMessage(AgentFinishReason $reason): ?string
{
    return match ($reason) {
        AgentFinishReason::Stop, AgentFinishReason::Done => null,
        AgentFinishReason::Refusal => 'The model declined to summarize the README.',
        AgentFinishReason::ContentFilter => 'The provider\'s content filter stopped the response.',
        AgentFinishReason::Error => 'The agent stopped with an error. Check the server log.',
        AgentFinishReason::ContextWindowExceeded => 'The README did not fit the model\'s context window.',
        default => 'The agent stopped early (' . $reason->value . ').',
    };
}

/**
 * A short notice for a provider stop other than a normal finish, or null.
 */
function providerNotice(ProviderFinishReason $reason): ?string
{
    return match ($reason) {
        ProviderFinishReason::Stop, ProviderFinishReason::ToolUse => null,
        default => '[stopped: ' . $reason->value . ']',
    };
}

/**
 * Strip C0 and C1 control characters (ANSI/OSC escapes) except newline and tab,
 * so model text cannot drive the terminal.
 */
function sanitizeTerminal(string $text): string
{
    return preg_replace('/[\x00-\x08\x0B-\x1F\x7F-\x{9F}]/u', '', $text)
        ?? preg_replace('/[^\x09\x0A\x20-\x7E]/', '', $text)
        ?? '';
}
