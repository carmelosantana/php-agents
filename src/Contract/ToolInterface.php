<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Tool\Parameter\Parameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Contract for tools that agents can call.
 */
interface ToolInterface
{
    /**
     * Unique name for this tool (snake_case).
     */
    public function name(): string;

    /**
     * Human-readable description.
     */
    public function description(): string;

    /**
     * Typed parameter definitions for this tool.
     *
     * @return Parameter[]
     */
    public function parameters(): array;

    /**
     * Execute the tool with validated input.
     *
     * @param array<string, mixed> $input Validated parameter values
     */
    public function execute(array $input): ToolResult;

    /**
     * Convert this tool definition to an OpenAI-compatible function schema.
     *
     * @return array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public function toFunctionSchema(): array;
}
