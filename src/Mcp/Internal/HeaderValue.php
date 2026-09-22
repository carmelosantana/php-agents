<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Internal;

/**
 * The 2026-07-28 header value encoding (streamable-http §Value Encoding), used for
 * `Mcp-Name` and for every `Mcp-Param-*`.
 *
 * A value of at least one character, made only of visible ASCII (0x21-0x7E) and inner
 * spaces, is sent as is. Everything else is sent as `=?base64?<base64 of the UTF-8
 * bytes>?=`, and so is a plain value that already looks like that wrapper, which the
 * spec base64-encodes too "to avoid ambiguity". The spec words that resemblance as a
 * value starting with `=?base64?` and ending with `?=`, so the test below is those two
 * calls rather than one pattern: in `=?base64?=` the prefix and the suffix overlap, and
 * a pattern asking for a prefix, then anything, then a suffix does not match it. The
 * markers are matched as the spec writes them, in lower case.
 *
 * Two values the spec would allow through plain are wrapped here anyway: its safe set
 * also holds the horizontal tab, and it says nothing about the empty string, which the
 * pattern below rejects for want of a character. Both stay readable to a conforming
 * server, which MUST decode the sentinel before comparing a header against the body,
 * and wrapping the empty string keeps this class from ever returning an empty header
 * value. An empty value is legal HTTP but awkward to transmit — curl needs a separate
 * syntax for it, which symfony/http-client v8.1.7 works around in
 * CurlHttpClient.php:230-232 — and McpClient's HTTP client comes from the host, while
 * a `Mcp-Param-*` that arrives missing is a -32020 HeaderMismatch.
 *
 * The returned value carries no CR or LF: the plain branch has matched a pattern that
 * admits neither, and base64_encode() emits only its own alphabet. That pattern ends in
 * the `D` modifier, without which PCRE lets `$` match before a final newline and
 * `encode("trailing\n")` hands that newline back unchanged.
 *
 * @internal
 */
final class HeaderValue
{
    public static function encode(string $value): string
    {
        $plain = preg_match('/^[\x21-\x7E]([\x20-\x7E]*[\x21-\x7E])?$/D', $value) === 1
            && !(str_starts_with($value, '=?base64?') && str_ends_with($value, '?='));

        return $plain ? $value : '=?base64?' . base64_encode($value) . '?=';
    }
}
