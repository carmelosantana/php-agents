<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Policy that gates tool execution before it happens.
 *
 * Implementations can prompt for user confirmation, enforce allowlists,
 * or apply any other pre-execution logic. Returning `true` allows
 * execution; returning a string denies it with that message.
 */
interface ToolExecutionPolicyInterface
{
    /**
     * Decide whether a tool call should proceed.
     *
     * @param string $toolName The name of the tool being invoked
     * @param array<string, mixed> $arguments The arguments the LLM provided
     * @return true|string True to allow, or an error/denial message string
     */
    public function shouldExecute(string $toolName, array $arguments): true|string;
}
