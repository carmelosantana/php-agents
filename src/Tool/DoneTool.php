<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

/**
 * Factory for the built-in "done" tool that signals agent completion.
 *
 * Returns a standard Tool instance using the same Parameter-driven schema
 * generation as all other tools — no hand-written toFunctionSchema().
 */
final class DoneTool
{
    public const NAME = 'done';

    /**
     * Create the done tool instance.
     *
     * Uses the generic Tool class with a StringParameter, ensuring
     * consistent schema generation across all tools.
     */
    public static function create(): ToolInterface
    {
        return new Tool(
            name: self::NAME,
            description: 'Present your final response to the user after using tools. '
                . 'Pass your completed answer in the "response" parameter. '
                . 'Only needed after tool use — for simple conversation, respond with text directly.',
            parameters: [
                new StringParameter(
                    name: 'response',
                    description: 'Your final response to the user\'s request.',
                    required: true,
                ),
            ],
            callback: fn(array $input): ToolResult => ToolResult::success($input['response'] ?? ''),
        );
    }
}
