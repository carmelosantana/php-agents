<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool;

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

final readonly class ToolResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ToolResultStatus $status,
        public string $content,
        public ?string $callId = null,
        public array $metadata = [],
        public ?string $mimeType = null,
        public ?string $displayHint = null,
        public ?bool $retryable = null,
        public ?string $errorCode = null,
    ) {}

    public static function success(string $content): self
    {
        return new self(ToolResultStatus::Success, $content);
    }

    public static function error(string $message): self
    {
        return new self(ToolResultStatus::Error, $message);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public static function json(array $payload, array $metadata = []): self
    {
        return new self(
            ToolResultStatus::Success,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
            metadata: $metadata,
            mimeType: 'application/json',
            displayHint: 'structured-json',
        );
    }

    public function withCallId(string $callId): self
    {
        return new self(
            $this->status,
            $this->content,
            $callId,
            $this->metadata,
            $this->mimeType,
            $this->displayHint,
            $this->retryable,
            $this->errorCode,
        );
    }

    public function withContent(string $content): self
    {
        return new self(
            $this->status,
            $content,
            $this->callId,
            $this->metadata,
            $this->mimeType,
            $this->displayHint,
            $this->retryable,
            $this->errorCode,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->status,
            $this->content,
            $this->callId,
            array_replace($this->metadata, $metadata),
            $this->mimeType,
            $this->displayHint,
            $this->retryable,
            $this->errorCode,
        );
    }

    public function withMimeType(?string $mimeType): self
    {
        return new self(
            $this->status,
            $this->content,
            $this->callId,
            $this->metadata,
            $mimeType,
            $this->displayHint,
            $this->retryable,
            $this->errorCode,
        );
    }

    public function withDisplayHint(?string $displayHint): self
    {
        return new self(
            $this->status,
            $this->content,
            $this->callId,
            $this->metadata,
            $this->mimeType,
            $displayHint,
            $this->retryable,
            $this->errorCode,
        );
    }

    public function withRetryable(?bool $retryable): self
    {
        return new self(
            $this->status,
            $this->content,
            $this->callId,
            $this->metadata,
            $this->mimeType,
            $this->displayHint,
            $retryable,
            $this->errorCode,
        );
    }

    public function withErrorCode(?string $errorCode): self
    {
        return new self(
            $this->status,
            $this->content,
            $this->callId,
            $this->metadata,
            $this->mimeType,
            $this->displayHint,
            $this->retryable,
            $errorCode,
        );
    }
}
