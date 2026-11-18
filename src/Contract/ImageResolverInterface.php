<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Image\ResolvedImage;

/**
 * Turns an image reference into checked image bytes.
 *
 * $reference is a filesystem path, a `file://` URL or an `http(s)://` URL. Data URIs are
 * decoded by the caller and never reach a resolver.
 */
interface ImageResolverInterface
{
    /** @throws ImageInputException when the reference is refused or does not hold an allowed image. */
    public function resolve(string $reference): ResolvedImage;
}
