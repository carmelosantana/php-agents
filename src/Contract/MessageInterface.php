<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * Contract for conversation messages.
 */
interface MessageInterface
{
    /**
     * Message role (system, user, assistant, tool).
     */
    public function role(): Role;

    /**
     * Message content.
     *
     * @return string|array<array{type: string, text?: string, image_url?: array<string, mixed>}>
     */
    public function content(): string|array;

    /**
     * Tool calls in this message (assistant messages only).
     *
     * @return ToolCall[]
     */
    public function toolCalls(): array;

    /**
     * Tool call ID this message responds to (tool result messages only).
     */
    public function toolCallId(): ?string;

    /**
     * Convert to provider-specific array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
