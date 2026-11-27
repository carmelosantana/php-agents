<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Internal;

/**
 * Reads a token count from a provider's usage payload. Not public API.
 *
 * Accepts a non-negative int, a finite integral non-negative float inside the int
 * range, or a string of ASCII digits that fits in an int. Everything else is null.
 */
final class TokenCount
{
    public static function from(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            // (float) PHP_INT_MAX rounds up to 2^63, the first float past the int range.
            return is_finite($value) && $value >= 0 && $value < (float) PHP_INT_MAX && floor($value) === $value
                ? (int) $value
                : null;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $digits = ltrim($value, '0');
            if ($digits === '') {
                return 0;
            }

            // (int) saturates at PHP_INT_MAX, so an overflowing string no longer round-trips.
            $count = (int) $digits;

            return (string) $count === $digits ? $count : null;
        }

        return null;
    }
}
