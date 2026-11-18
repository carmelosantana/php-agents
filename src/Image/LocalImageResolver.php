<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Image;

use CarmeloSantana\PHPAgents\Contract\ImageResolverInterface;

/**
 * Reads an image from a path (or `file://` URL) that sits under one of $allowedRoots.
 *
 * The reference and every root are realpath()ed, so `..` segments and symlinks are
 * judged by where they really lead; the real path must start with a real root plus `/`.
 * A root that does not exist is ignored, and no roots refuses everything.
 *
 * The file is opened then verified: stat() must show a regular file no larger than the
 * policy's maxBytes (so a FIFO or device is never opened), fstat() on the open handle
 * must show a regular file with the same device and inode, and the reference must still
 * realpath() to the same contained path. At most maxBytes + 1 bytes are read, and more
 * than maxBytes refuses, which covers a file that grows after the size check. The bytes
 * then pass ImagePolicy::check().
 *
 * Residual race: an ancestor directory swapped for a symlink between the first
 * realpath() and fopen() is caught by the inode and second realpath() checks only when
 * the swap is still in place after the open; a swap that is undone in that window is not
 * detected. PHP has no openat()/O_NOFOLLOW to close it, so roots should not be writable
 * by whoever supplies the reference.
 */
final class LocalImageResolver implements ImageResolverInterface
{
    private const int S_IFMT = 0170000;
    private const int S_IFREG = 0100000;

    /**
     * @param list<string> $allowedRoots
     */
    public function __construct(
        private readonly array $allowedRoots,
        private readonly ImagePolicy $policy = new ImagePolicy(),
    ) {}

    public function resolve(string $reference): ResolvedImage
    {
        $path = str_starts_with(strtolower($reference), 'file://') ? substr($reference, 7) : $reference;
        if (str_contains($path, "\0")) {
            throw new ImageInputException('Image path contains a NUL byte.');
        }

        $real = $this->containedRealPath($path);

        $stat = @stat($real);
        if ($stat === false || ($stat['mode'] & self::S_IFMT) !== self::S_IFREG) {
            throw new ImageInputException('Image path is not a regular file.');
        }
        $maxBytes = $this->policy->maxBytes;
        if ($stat['size'] > $maxBytes) {
            throw new ImageInputException(sprintf('Image file size %d exceeds %d bytes.', $stat['size'], $maxBytes));
        }

        $handle = @fopen($real, 'rb');
        if ($handle === false) {
            throw new ImageInputException('Image file could not be opened.');
        }

        try {
            $opened = fstat($handle);
            if ($opened === false
                || ($opened['mode'] & self::S_IFMT) !== self::S_IFREG
                || $opened['dev'] !== $stat['dev']
                || $opened['ino'] !== $stat['ino']
                || $this->containedRealPath($path) !== $real
            ) {
                throw new ImageInputException('Image file changed while it was being opened.');
            }

            $bytes = stream_get_contents($handle, $maxBytes + 1);
        } finally {
            fclose($handle);
        }

        if ($bytes === false) {
            throw new ImageInputException('Image file could not be read.');
        }
        if (strlen($bytes) > $maxBytes) {
            throw new ImageInputException(sprintf('Image file holds more than %d bytes.', $maxBytes));
        }

        return new ResolvedImage($this->policy->check($bytes), $bytes);
    }

    /** The real path of $path when it sits under a real allowed root; throws otherwise. */
    private function containedRealPath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            foreach ($this->allowedRoots as $root) {
                $realRoot = realpath($root);
                if ($realRoot !== false && str_starts_with($real, rtrim($realRoot, '/') . '/')) {
                    return $real;
                }
            }
        }

        throw new ImageInputException('Image path is outside the allowed roots.');
    }
}
