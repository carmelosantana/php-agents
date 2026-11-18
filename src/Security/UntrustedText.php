<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Security;

/**
 * Cleans text that came from outside the process — a provider's error message, a tool's
 * exception, an MCP server's result — before it reaches a user, an event or the model.
 *
 * redactSecrets() replaces credential-shaped strings with `[redacted]`: a Bearer token or
 * Basic credential (the scheme is kept), OpenAI/Anthropic `sk-` keys, Google `AIza` keys,
 * xAI keys, GitHub tokens, AWS access key ids, and the value after a credential-named key
 * such as `api_key=`, `Authorization:` or `"password": "…"` (the key is kept; a quoted
 * value is redacted whole, up to its closing quote past any backslash escapes, or to the
 * end of the text when it is never closed). The Bearer and Basic scheme words match in
 * any case. It is a pattern match: an opaque credential that matches none of these shapes
 * is not removed. If PCRE fails, the whole text becomes `[redacted]`.
 *
 * scrub() turns control and format characters (ESC, bidi overrides, zero-width spaces)
 * into spaces, collapses whitespace, trims, and cuts to $maxChars with `…`.
 */
final class UntrustedText
{
    private const REDACTED = '[redacted]';

    // Every quantifier is possessive, so a long run that ends without a match (a key,
    // a megabyte of spaces, no `:`) fails in linear time instead of backtracking.

    /** @var list<string> patterns whose whole match is replaced */
    private const TOKEN_PATTERNS = [
        '/\bsk-[A-Za-z0-9_-]{16,}+/',
        '/\bAIza[0-9A-Za-z_-]{30,}+/',
        '/\bxai-[A-Za-z0-9]{16,}+/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}+/',
        '/\bAKIA[0-9A-Z]{16}+/',
    ];

    /** The scheme matches in any case (`bearer`, `BEARER`) and is kept. */
    private const BEARER = '/((?i:Bearer)\s++)[A-Za-z0-9._~+\/=-]{8,}+/';

    /**
     * A Basic credential: base64 of at least 8 characters that is not a lowercase word. Only
     * the scheme is case-insensitive; the credential test stays case-sensitive.
     */
    private const BASIC = '/(\b(?i:Basic)\s++)(?=[a-z]*+[A-Z0-9+\/=])[A-Za-z0-9+\/]{8,}+=*+/';

    /**
     * A credential-named key, its separator, then a quoted value (whole, backslash escapes
     * included; an unclosed one runs to the end of the text) or a bare one.
     */
    private const KEY_VALUE = '/((?:api[_-]?key|x-api-key|x-goog-api-key|authorization|token|secret|password)\s*+["\']?+\s*+[:=]\s*+)("(?:[^"\\\\]++|\\\\[\s\S]?+)*+"?+|\'(?:[^\'\\\\]++|\\\\[\s\S]?+)*+\'?+|["\']?+[^\s"\'&,}]++)/i';

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
        $text = preg_replace([self::BEARER, self::BASIC], '$1' . self::REDACTED, $text);
        $text = $text === null ? null : preg_replace(self::TOKEN_PATTERNS, self::REDACTED, $text);
        $text = $text === null ? null : preg_replace_callback(self::KEY_VALUE, static function (array $m): string {
            $value = $m[2];
            $quote = $value[0] === '"' || $value[0] === "'" ? $value[0] : '';
            $closed = $quote !== '' && strlen($value) > 1 && str_ends_with($value, $quote);

            return $m[1] . $quote . self::REDACTED . ($closed ? $quote : '');
        }, $text);

        // A PCRE failure fails closed: nothing of the text is shown.
        return $text ?? self::REDACTED;
    }

    /** redactSecrets() then scrub(): redaction runs first, so a cut never splits a secret. */
    public static function forDisplay(string $text, int $maxChars = 500): string
    {
        return self::scrub(self::redactSecrets($text), $maxChars);
    }
}
