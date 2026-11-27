<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

final class Protocol
{
    public const array VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];

    public static function negotiate(mixed $requested): string
    {
        return in_array($requested, self::VERSIONS, true) ? $requested : self::VERSIONS[0];
    }
}
