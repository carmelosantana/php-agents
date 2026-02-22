<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

final readonly class AssistantMessage implements MessageInterface
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        private string $content = '',
        private array $toolCalls = [],
    ) {}

    public function role(): Role
    {
        return Role::Assistant;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    public function toolCallId(): ?string
    {
        return null;
    }

    public function toArray(): array
    {
        $arr = [
            'role' => 'assistant',
            'content' => $this->content,
        ];

        if (!empty($this->toolCalls)) {
            $arr['tool_calls'] = array_map(fn(ToolCall $tc) => [
                'id' => $tc->id,
                'type' => 'function',
                'function' => [
                    'name' => $tc->name,
                    'arguments' => json_encode($tc->arguments ?: new \stdClass()),
                ],
            ], $this->toolCalls);
        }

        return $arr;
    }
}
