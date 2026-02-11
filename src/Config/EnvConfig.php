<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Config;

final class EnvConfig
{
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
