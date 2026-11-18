<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

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

    public static function requestRefused(string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Provider request refused: %s', $reason), 0, $previous);
    }

    /** A 3xx answer that was not followed, refused with Symfony's status message. */
    public static function redirectRefused(RedirectionExceptionInterface $e): self
    {
        return self::requestRefused(sprintf('redirect not followed (%s)', $e->getMessage()), $e);
    }
}
