<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

final class ProviderException extends \RuntimeException
{
    public static function chatFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Provider chat request failed: %s', $reason),
            0,
            $previous,
        );
    }

    public static function embeddingFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Embedding request failed: %s', $reason),
            0,
            $previous,
        );
    }
}
