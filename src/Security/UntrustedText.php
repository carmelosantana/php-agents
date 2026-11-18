<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Security;

/**
 * Cleans text that came from outside the process — a provider's error message, a tool's
 * exception, an MCP server's result — before it reaches a user, an event or the model.
 *
 * redactSecrets() replaces credential-shaped strings with `[redacted]`: a Bearer token
 * (the scheme is kept), OpenAI/Anthropic `sk-` keys, Google `AIza` keys, xAI keys, GitHub
 * tokens, AWS access key ids, and the value after a credential-named key such as
 * `api_key=`, `Authorization:` or `"password": "…"` (the key is kept). It is a pattern
 * match: an opaque credential that matches none of these shapes is not removed.
 *
 * scrub() turns control and format characters (ESC, bidi overrides, zero-width spaces)
 * into spaces, collapses whitespace, trims, and cuts to $maxChars with `…`.
 */
final class UntrustedText
{
    private const REDACTED = '[redacted]';

    /** @var list<string> patterns whose whole match is replaced */
    private const TOKEN_PATTERNS = [
        '/\bsk-[A-Za-z0-9_-]{16,}/',
        '/\bAIza[0-9A-Za-z_-]{30,}/',
        '/\bxai-[A-Za-z0-9]{16,}/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',
        '/\bAKIA[0-9A-Z]{16}/',
    ];

    private const BEARER = '/(Bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/';

    private const KEY_VALUE = '/((?:api[_-]?key|x-api-key|x-goog-api-key|authorization|token|secret|password)\s*["\']?\s*[:=]\s*["\']?)[^\s"\'&,}]+/i';

    public static function scrub(string $text, int $maxChars): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', mb_scrub($text, 'UTF-8')));
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = mb_substr($text, 0, $maxChars, 'UTF-8') . '…';
        }

        return $text;
    }

    public static function redactSecrets(string $text): string
    {
        $text = (string) preg_replace(self::BEARER, '$1' . self::REDACTED, $text);
        $text = (string) preg_replace(self::TOKEN_PATTERNS, self::REDACTED, $text);

        return (string) preg_replace(self::KEY_VALUE, '$1' . self::REDACTED, $text);
    }

    /** redactSecrets() then scrub(): redaction runs first, so a cut never splits a secret. */
    public static function forDisplay(string $text, int $maxChars = 500): string
    {
        return self::scrub(self::redactSecrets($text), $maxChars);
    }
}
