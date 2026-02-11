<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\Role;

final class Conversation
{
    /** @var MessageInterface[] */
    private array $messages = [];

    public function add(MessageInterface $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return MessageInterface[]
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return MessageInterface[]
     */
    public function all(): array
    {
        return $this->messages;
    }

    public function last(): ?MessageInterface
    {
        if (empty($this->messages)) {
            return null;
        }

        return $this->messages[array_key_last($this->messages)];
    }

    public function first(): ?MessageInterface
    {
        if (empty($this->messages)) {
            return null;
        }

        return $this->messages[array_key_first($this->messages)];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn(MessageInterface $m) => $m->toArray(), $this->messages);
    }

    /**
     * Filter messages by role.
     *
     * @return MessageInterface[]
     */
    public function filter(Role $role): array
    {
        return array_filter($this->messages, fn(MessageInterface $m) => $m->role() === $role);
    }

    /**
     * Estimate total tokens (rough: 1 token ≈ 4 chars).
     */
    public function estimateTokens(): int
    {
        $chars = 0;
        foreach ($this->messages as $msg) {
            $content = $msg->content();
            $chars += is_string($content) ? strlen($content) : strlen(json_encode($content) ?: '');
        }

        return (int) ceil($chars / 4);
    }
}
