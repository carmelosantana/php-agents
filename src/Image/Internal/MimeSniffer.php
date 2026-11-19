<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Image\Internal;

/**
 * Sniffs a MIME type from bytes. Uses finfo when ext-fileinfo is loaded; otherwise
 * getimagesizefromstring() (ext-standard), which knows the image formats only, so
 * anything that is not an image sniffs to null there.
 */
final class MimeSniffer
{
    public static function finfoAvailable(): bool
    {
        return class_exists(\finfo::class);
    }

    /**
     * @param bool|null $useFinfo null picks finfo when it is available; false forces the fallback.
     */
    public static function sniff(string $bytes, ?bool $useFinfo = null): ?string
    {
        if ($useFinfo ?? self::finfoAvailable()) {
            $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);

            return is_string($mime) ? $mime : null;
        }

        // getimagesizefromstring() raises a notice on short or truncated input. A failed sniff
        // is null, so the notice is swallowed here rather than reaching the host's error handler.
        set_error_handler(static fn(): bool => true);
        try {
            $info = getimagesizefromstring($bytes);
        } finally {
            restore_error_handler();
        }

        return $info !== false && $info['mime'] !== '' ? $info['mime'] : null;
    }
}
