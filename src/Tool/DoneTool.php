<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class DoneTool implements ToolInterface
{
    public const NAME = 'done';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Call this tool when you have completed the task. '
            . 'Pass your final response in the "response" parameter. '
            . 'You MUST call this tool to finish — do not end without it.';
    }

    public function parameters(): array
    {
        return [
            new StringParameter(
                name: 'response',
                description: 'Your final response to the user\'s request.',
                required: true,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        return new ToolResult(
            ToolResultStatus::Success,
            $input['response'] ?? '',
        );
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'response' => [
                            'type' => 'string',
                            'description' => 'Your final response to the user\'s request.',
                        ],
                    ],
                    'required' => ['response'],
                ],
            ],
        ];
    }
}
