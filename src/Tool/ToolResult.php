<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool;

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

final readonly class ToolResult
{
    public function __construct(
        public ToolResultStatus $status,
        public string $content,
        public ?string $callId = null,
    ) {}

    public static function success(string $content): self
    {
        return new self(ToolResultStatus::Success, $content);
    }

    public static function error(string $message): self
    {
        return new self(ToolResultStatus::Error, $message);
    }

    public function withCallId(string $callId): self
    {
        return new self($this->status, $this->content, $callId);
    }
}
