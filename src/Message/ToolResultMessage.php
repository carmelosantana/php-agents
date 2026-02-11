<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

final readonly class ToolResultMessage implements MessageInterface
{
    public function __construct(
        private ToolResult $result,
    ) {}

    public function role(): Role
    {
        return Role::Tool;
    }

    public function content(): string
    {
        return $this->result->content;
    }

    public function toolCalls(): array
    {
        return [];
    }

    public function toolCallId(): ?string
    {
        return $this->result->callId;
    }

    public function toArray(): array
    {
        return [
            'role' => 'tool',
            'content' => $this->result->content,
            'tool_call_id' => $this->result->callId,
        ];
    }
}
