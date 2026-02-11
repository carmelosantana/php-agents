<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\Role;

final readonly class SystemMessage implements MessageInterface
{
    public function __construct(
        private string $content,
    ) {}

    public function role(): Role
    {
        return Role::System;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function toolCalls(): array
    {
        return [];
    }

    public function toolCallId(): ?string
    {
        return null;
    }

    public function toArray(): array
    {
        return [
            'role' => 'system',
            'content' => $this->content,
        ];
    }
}
