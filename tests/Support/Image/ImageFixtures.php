<?php

declare(strict_types=1);

namespace Tests\Support\Image;

/** Image bytes for resolver tests, built in memory so no binary fixture is committed. */
final class ImageFixtures
{
    /** A 67-byte 1x1 PNG. */
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    /** A 1x1 JPEG written by GD. */
    private const string JPEG_1X1_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=';

    /** A 35-byte 1x1 GIF written by GD. */
    private const string GIF_1X1_BASE64 = 'R0lGODdhAQABAIAAAAQCBAAAACwAAAAAAQABAAACAkQBADs=';

    /** A 44-byte 1x1 WebP written by GD. */
    private const string WEBP_1X1_BASE64 = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v02aAA=';

    public static function png(): string
    {
        return self::decode(self::PNG_1X1_BASE64);
    }

    public static function jpeg(): string
    {
        return self::decode(self::JPEG_1X1_BASE64);
    }

    public static function gif(): string
    {
        return self::decode(self::GIF_1X1_BASE64);
    }

    public static function webp(): string
    {
        return self::decode(self::WEBP_1X1_BASE64);
    }

    private static function decode(string $base64): string
    {
        return base64_decode($base64, true) ?: throw new \LogicException('Bad image fixture');
    }
}
