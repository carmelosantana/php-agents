<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

/**
 * Tool-call argument JSON as providers stream it.
 */
final class ToolArguments
{
    /**
     * Decode a call's complete argument JSON. Empty or whitespace arguments (sent by
     * some servers for parameterless tools) are `{}`; anything that is not a JSON
     * object (truncated, array, scalar) is null.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return [];
        }

        if (!json_decode($json) instanceof \stdClass) {
            return null;
        }

        /** @var array<string, mixed> */
        return json_decode($json, true);
    }

    /**
     * Arguments from a decoded (non-streaming) response body: a wire string is decoded as
     * decode() does, a missing value is `{}`, an associative array (a JSON object, `{}`
     * included) is kept, and anything else (a list, a scalar) is null.
     *
     * @return array<string, mixed>|null
     */
    public static function fromDecoded(mixed $arguments): ?array
    {
        return match (true) {
            is_string($arguments) => self::decode($arguments),
            $arguments === null, $arguments === [] => [],
            is_array($arguments) && !array_is_list($arguments) => $arguments,
            default => null,
        };
    }

    /**
     * Argument JSON from a decoded SSE payload. Wire strings pass through; arguments
     * sent as a JSON object arrive decoded, so they are re-encoded, with an empty
     * object (decoded as []) becoming `{}`. A missing value is ''.
     */
    public static function toJson(mixed $arguments): string
    {
        return match (true) {
            is_string($arguments) => $arguments,
            $arguments === null => '',
            $arguments === [] => '{}',
            default => (string) json_encode($arguments),
        };
    }
}
