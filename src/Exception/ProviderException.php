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

    private const int STREAM_ERROR_MAX_CHARS = 300;

    /**
     * An `error` event inside a provider's stream, as `type: message`. The
     * provider's text is untrusted: control characters become spaces,
     * whitespace collapses, the message is cut to 300 characters (with `…`)
     * and a type that is not 1-64 of `[a-z0-9_]` becomes `error`.
     */
    public static function streamError(string $type, string $message): self
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $type) !== 1) {
            $type = 'error';
        }

        $message = (string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cc}/u', ' ', mb_scrub($message, 'UTF-8')));
        $message = trim($message);
        if (mb_strlen($message, 'UTF-8') > self::STREAM_ERROR_MAX_CHARS) {
            $message = mb_substr($message, 0, self::STREAM_ERROR_MAX_CHARS, 'UTF-8') . '…';
        }

        return new self(sprintf('%s: %s', $type, $message));
    }

    /** A 3xx answer that was not followed, refused with Symfony's status message. */
    public static function redirectRefused(RedirectionExceptionInterface $e): self
    {
        return self::requestRefused(sprintf('redirect not followed (%s)', $e->getMessage()), $e);
    }
}
