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
 * scrub() turns control and format characters (ESC, bidi overrides, zero-width spaces) and
 * the Hangul fillers into spaces, collapses whitespace, trims, and cuts to $maxChars with `…`
 * (so the result is at most $maxChars + 1 characters). A cut never leaves a combining mark's
 * letter without its mark.
 *
 * forDisplay() also catches a credential split by an invisible character; see there.
 */
final class UntrustedText
{
    private const REDACTED = '[redacted]';

    // Every quantifier is possessive, so a long run that ends without a match (a key,
    // a megabyte of spaces, no `:`) fails in linear time instead of backtracking.

    // A key prefix must not follow a letter or digit (so `task-…` and `risk-…` are words), but
    // it may follow `_` or another punctuation mark: `reason_sk-…` is a key.

    /** @var list<string> patterns whose whole match is replaced */
    private const TOKEN_PATTERNS = [
        '/(?<![A-Za-z0-9])sk-[A-Za-z0-9_-]{16,}+/',
        '/(?<![A-Za-z0-9])AIza[0-9A-Za-z_-]{30,}+/',
        '/(?<![A-Za-z0-9])xai-[A-Za-z0-9]{16,}+/',
        '/(?<![A-Za-z0-9])gh[pousr]_[A-Za-z0-9]{20,}+/',
        '/(?<![A-Za-z0-9])AKIA[0-9A-Z]{16}+/',
    ];

    /** The scheme matches in any case (`bearer`, `BEARER`) and is kept. */
    private const BEARER = '/((?i:Bearer)\s++)[A-Za-z0-9._~+\/=-]{8,}+/';

    /**
     * A Basic credential: base64 of at least 8 characters that is not a lowercase word. Only
     * the scheme is case-insensitive; the credential test stays case-sensitive.
     */
    private const BASIC = '/((?<![A-Za-z0-9])(?i:Basic)\s++)(?=[a-z]*+[A-Z0-9+\/=])[A-Za-z0-9+\/]{8,}+=*+/';

    /**
     * A credential-named key, its separator, then a quoted value (whole, backslash escapes
     * included; an unclosed one runs to the end of the text) or a bare one.
     */
    private const KEY_VALUE = '/((?:api[_-]?key|x-api-key|x-goog-api-key|authorization|token|secret|password)\s*+["\']?+\s*+[:=]\s*+)("(?:[^"\\\\]++|\\\\[\s\S]?+)*+"?+|\'(?:[^\'\\\\]++|\\\\[\s\S]?+)*+\'?+|["\']?+[^\s"\'&,}]++)/i';

    /**
     * Control and format characters, plus the Hangul fillers, which render as blank space but
     * are letters to Unicode.
     */
    private const INVISIBLE_CLASS = '[\p{Cc}\p{Cf}\x{115F}\x{1160}\x{3164}\x{FFA0}]';

    private const INVISIBLE = '/' . self::INVISIBLE_CLASS . '/u';

    /** The invisible characters forDisplay() marks: all but tabs and line breaks, which are ordinary whitespace. */
    private const MARKABLE = '/(?![\t\n\f\r])' . self::INVISIBLE_CLASS . '/u';

    /** Stands in for an invisible character during redaction: PCRE's `\s` matches it. */
    private const MARK = "\v";

    public static function scrub(string $text, int $maxChars): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::INVISIBLE, ' ', mb_scrub($text, 'UTF-8')));
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $cut = mb_substr($text, 0, $maxChars, 'UTF-8');
            // A cut before a combining mark would strip it off its letter: drop that letter too.
            if (preg_match('/^\p{M}/u', mb_substr($text, $maxChars, 1, 'UTF-8')) === 1) {
                $cut = (string) preg_replace('/\X$/u', '', $cut);
            }
            $text = $cut . '…';
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

    /**
     * redactSecrets() then scrub(): redaction runs first, so a cut never splits a secret.
     *
     * Invisible characters are marked before redaction, so one between a scheme and its
     * credential (`Bearer<U+202E>abc…`) reads as whitespace. If deleting the marks reveals a
     * credential that one split (`sk-AAAA<U+200B>BBBB…`), the text is redacted in that joined
     * form, where the invisible characters are gone rather than spaces.
     */
    public static function forDisplay(string $text, int $maxChars = 500): string
    {
        $marked = (string) preg_replace(self::MARKABLE, self::MARK, mb_scrub($text, 'UTF-8'));
        $redacted = self::redactSecrets($marked);
        $joined = str_replace(self::MARK, '', $redacted);
        $rejoined = self::redactSecrets($joined);

        return self::scrub($rejoined === $joined ? $redacted : $rejoined, $maxChars);
    }
}
