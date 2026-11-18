<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Image;

/** Image bytes and the MIME type sniffed from them. */
final readonly class ResolvedImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {}
}
