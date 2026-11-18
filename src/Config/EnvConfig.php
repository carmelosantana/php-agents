<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Config;

/**
 * Read-only view over captured environment values with a getenv() fallback.
 *
 * Captured values never leave the object through serialize(), var_dump() or
 * print_r(): serialization drops them and debug dumps show the key names with
 * '[redacted]' values. var_export(), (array) casts and Reflection bypass these
 * hooks; do not export, cast or reflect on an EnvConfig.
 *
 * An unserialized copy carries no captured values, including any a crafted
 * payload supplies, and reads getenv() only: its authority changes to the
 * ambient environment of the process that unserializes it. Rehydrate
 * deliberately with new EnvConfig(...) instead.
 */
final class EnvConfig
{
    private const REDACTED = '[redacted]';

    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly array $env = [],
    ) {}

    public static function fromGlobals(): self
    {
        return new self($_ENV);
    }

    /**
     * @return array{env: array<string, string>}
     */
    public function __serialize(): array
    {
        return ['env' => []];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->env = [];
    }

    /**
     * @return array{env: array<string, string>}
     */
    public function __debugInfo(): array
    {
        return ['env' => array_fill_keys(array_keys($this->env), self::REDACTED)];
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (isset($this->env[$key])) {
            return $this->env[$key];
        }

        $value = getenv($key);

        return $value !== false ? $value : $default;
    }

    public function has(string $key): bool
    {
        return isset($this->env[$key]) || getenv($key) !== false;
    }

    public function getString(string $key, string $default = ''): string
    {
        return $this->get($key, $default) ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value !== null ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return string[]
     */
    public function getArray(string $key, string $separator = ','): array
    {
        $value = $this->get($key);

        if ($value === null || $value === '' || $separator === '') {
            return [];
        }

        return array_map('trim', explode($separator, $value));
    }
}
