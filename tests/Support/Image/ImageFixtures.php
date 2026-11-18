<?php

declare(strict_types=1);

namespace Tests\Support\Image;

/** Image bytes for resolver tests, built in memory so no binary fixture is committed. */
final class ImageFixtures
{
    /** A 67-byte 1x1 PNG. */
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    public static function png(): string
    {
        return base64_decode(self::PNG_1X1_BASE64, true) ?: throw new \LogicException('Bad PNG fixture');
    }
}
