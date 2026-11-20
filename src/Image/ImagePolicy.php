<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Image;

use CarmeloSantana\PHPAgents\Image\Internal\MimeSniffer;

/**
 * What image bytes are accepted: at most $maxBytes, and a MIME type sniffed from the
 * bytes that is in $allowedMimeTypes. The sniff uses finfo, or getimagesizefromstring()
 * when ext-fileinfo is not loaded (that fallback recognises image formats only). $maxBytes is at least 1.
 */
final readonly class ImagePolicy
{
    /**
     * @param list<string> $allowedMimeTypes
     * @throws \InvalidArgumentException when $maxBytes is below 1.
     */
    public function __construct(
        public int $maxBytes = 20_971_520,
        public array $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
    ) {
        // 0 would reach RequestLimits as "no response cap" through RemoteImageResolver.
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be at least 1.');
        }
    }

    /**
     * Returns the MIME type sniffed from $bytes. $declaredMime (a Content-Type header, a
     * file extension's guess) plays no part in the decision: the sniffed type wins.
     *
     * @throws ImageInputException when $bytes is empty, over $maxBytes, or sniffs to a type not allowed.
     */
    public function check(string $bytes, ?string $declaredMime = null): string
    {
        if ($bytes === '') {
            throw new ImageInputException('Image is empty.');
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new ImageInputException(sprintf('Image is larger than %d bytes.', $this->maxBytes));
        }

        $mime = MimeSniffer::sniff($bytes);
        if ($mime === null || !in_array($mime, $this->allowedMimeTypes, true)) {
            throw new ImageInputException(sprintf('Image type %s is not allowed.', $mime ?? 'unknown'));
        }

        return $mime;
    }
}
