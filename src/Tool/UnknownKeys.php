<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool;

/**
 * Formats a list of argument names for an error a model can act on:
 * sorted, each name cut to 64 characters, at most 10 listed then `…`.
 *
 * @internal
 */
final class UnknownKeys
{
    private const MAX_NAMES = 10;
    private const MAX_NAME_LENGTH = 64;

    /**
     * The error for top-level arguments a schema does not declare, or null when every key is declared.
     *
     * @param array<int|string, mixed> $input
     * @param list<string> $declared
     */
    public static function error(array $input, array $declared): ?string
    {
        $unknown = array_diff(array_map('strval', array_keys($input)), $declared);

        if ($unknown === []) {
            return null;
        }

        return sprintf(
            'Unknown parameters: %s. Allowed: %s.',
            self::format($unknown),
            $declared === [] ? 'none' : self::format($declared),
        );
    }

    /**
     * @param array<int|string> $names
     */
    public static function format(array $names): string
    {
        $names = array_map(static fn(int|string $name): string => (string) $name, array_values($names));
        sort($names, SORT_STRING);

        $listed = array_map(
            static fn(string $name): string => mb_substr($name, 0, self::MAX_NAME_LENGTH),
            array_slice($names, 0, self::MAX_NAMES),
        );

        if (count($names) > self::MAX_NAMES) {
            $listed[] = '…';
        }

        return implode(', ', $listed);
    }
}
