<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

use CarmeloSantana\PHPAgents\Provider\RequestLimits;

/**
 * Tool-call argument JSON as providers stream it.
 */
final class ToolArguments
{
    /**
     * How arguments that arrive decoded are re-encoded: UTF-8 and slashes unescaped, so the
     * byte cap measures them as the wire string decode() sees would be measured.
     */
    private const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Decode a call's complete argument JSON. Empty or whitespace arguments (sent by
     * some servers for parameterless tools) are `{}`; anything that is not a JSON
     * object (truncated, array, scalar), or that is over $limits' tool-argument byte or
     * depth cap, is null. The JSON is decoded once, and only when it opens with `{`.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $json, ?RequestLimits $limits = null): ?array
    {
        $limits ??= new RequestLimits();
        $length = strlen($json);

        if ($limits->maxToolArgumentBytes > 0 && $length > $limits->maxToolArgumentBytes) {
            return null;
        }

        $start = strspn($json, " \t\n\r\0\x0B");
        if ($start === $length) {
            return [];
        }

        // Only an object decodes to an array that starts with `{`; a list starts with `[`.
        if ($json[$start] !== '{') {
            return null;
        }

        $decoded = json_decode($json, true, self::decodeDepth($limits));

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Arguments from a decoded (non-streaming) response body: a wire string is decoded as
     * decode() does, a missing value is `{}`, an associative array (a JSON object, `{}`
     * included) is kept when it is within $limits' tool-argument byte and depth caps, and
     * anything else (a list, a scalar) is null.
     *
     * @return array<string, mixed>|null
     */
    public static function fromDecoded(mixed $arguments, ?RequestLimits $limits = null): ?array
    {
        return match (true) {
            is_string($arguments) => self::decode($arguments, $limits),
            $arguments === null, $arguments === [] => [],
            is_array($arguments) && !array_is_list($arguments) => self::withinLimits($arguments, $limits ?? new RequestLimits()) ? $arguments : null,
            default => null,
        };
    }

    /**
     * Whether already-decoded arguments, re-encoded, fit the byte and depth caps.
     *
     * @param array<mixed> $arguments
     */
    private static function withinLimits(array $arguments, RequestLimits $limits): bool
    {
        // json_encode() counts depth one lower than json_decode(): `{"a":1}` is 1 deep.
        $json = json_encode($arguments, self::JSON_FLAGS, self::decodeDepth($limits) - 1);

        return $json !== false
            && ($limits->maxToolArgumentBytes === 0 || strlen($json) <= $limits->maxToolArgumentBytes);
    }

    /**
     * The json_decode() depth for $limits: it counts the value inside the outermost object as
     * a level, so it is one more than the nesting the cap allows. 0 is json_decode()'s 512, and
     * a cap above 2147483646 is clamped to it, since json_decode() takes at most 2147483647.
     *
     * @return int<2, 2147483647>
     */
    private static function decodeDepth(RequestLimits $limits): int
    {
        return $limits->maxToolArgumentDepth > 0 ? min($limits->maxToolArgumentDepth, 2_147_483_646) + 1 : 512;
    }

    /**
     * Argument JSON from a decoded SSE payload. Wire strings pass through; arguments
     * sent as a JSON object arrive decoded, so they are re-encoded (UTF-8 and slashes
     * unescaped), with an empty object (decoded as []) becoming `{}`; arguments that cannot be
     * encoded become `null`, so the call is dropped. A missing value is ''.
     */
    public static function toJson(mixed $arguments): string
    {
        return match (true) {
            is_string($arguments) => $arguments,
            $arguments === null => '',
            $arguments === [] => '{}',
            // Arguments json_encode() refuses (nested past its depth) become `null`, which
            // decode() drops, rather than '', which it would read as `{}`.
            default => json_encode($arguments, self::JSON_FLAGS) ?: 'null',
        };
    }
}
