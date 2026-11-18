<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Image;

/**
 * What image bytes are accepted: at most $maxBytes, and a MIME type sniffed from the
 * bytes (finfo) that is in $allowedMimeTypes.
 */
final readonly class ImagePolicy
{
    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        public int $maxBytes = 20_971_520,
        public array $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
    ) {}

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

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || !in_array($mime, $this->allowedMimeTypes, true)) {
            throw new ImageInputException(sprintf('Image type %s is not allowed.', is_string($mime) ? $mime : 'unknown'));
        }

        return $mime;
    }
}
