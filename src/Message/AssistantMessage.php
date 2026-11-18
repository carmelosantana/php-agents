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
     * @param list<array<string, mixed>> $contentBlocks Provider-native blocks (Anthropic thinking with its
     *        signature, text, tool_use) that the provider sends back unchanged. Not part of toArray().
     */
    public function __construct(
        private string $content = '',
        private array $toolCalls = [],
        private array $contentBlocks = [],
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

    /**
     * @return list<array<string, mixed>>
     */
    public function contentBlocks(): array
    {
        return $this->contentBlocks;
    }

    /**
     * The same message with no provider-native blocks, for history the
     * provider must re-format (a new run, or a conversation changed by pruning).
     */
    public function withoutContentBlocks(): self
    {
        return $this->contentBlocks === [] ? $this : new self($this->content, $this->toolCalls);
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
