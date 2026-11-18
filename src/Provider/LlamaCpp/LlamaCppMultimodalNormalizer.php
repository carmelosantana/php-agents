<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Contract\ImageResolverInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Image\ImagePolicy;
use CarmeloSantana\PHPAgents\Image\ResolvedImage;
use CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

/**
 * Turns `image_url` blocks into runtime image inputs.
 *
 * A `data:` URI is decoded and checked against the ImagePolicy. A path, `file://` or
 * `http(s)://` reference is refused unless the host configures an ImageResolverInterface
 * (or, for http(s), the legacy $remoteImageFetcher). Every image is checked against this
 * normalizer's ImagePolicy, whatever supplied it: resolver results too, even when the
 * resolver has its own policy, and the MIME type sniffed from the bytes is the one used.
 * Vision support and the image count are checked before any image is read.
 */
final class LlamaCppMultimodalNormalizer
{
    private readonly ?\Closure $remoteImageFetcher;

    /**
     * @param null|callable(string):string $remoteImageFetcher Host-trusted fetcher kept for
     *        backward compatibility; prefer an ImageResolverInterface such as RemoteImageResolver.
     */
    public function __construct(
        private readonly LlamaCppProjectorResolver $projectorResolver,
        null|callable $remoteImageFetcher = null,
        private readonly ?ImageResolverInterface $imageResolver = null,
        private readonly ImagePolicy $imagePolicy = new ImagePolicy(),
    ) {
        $this->remoteImageFetcher = $remoteImageFetcher === null
            ? null
            : \Closure::fromCallable($remoteImageFetcher);
    }

    /**
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     */
    public function normalize(array $messages, RuntimeModelMetadata $metadata, array $options = []): LlamaCppMultimodalContext
    {
        $references = $this->collectImageReferences($messages);
        if ($references === []) {
            return new LlamaCppMultimodalContext([], []);
        }

        if (!$metadata->supportsVision) {
            throw new \InvalidArgumentException("Model {$metadata->id} does not support image input.");
        }

        $maxImages = $this->resolveMaxImages($metadata, $options);
        if (count($references) > $maxImages) {
            throw new \InvalidArgumentException(
                "Model {$metadata->id} supports at most {$maxImages} image(s) per request.",
            );
        }

        $imageTokenCost = $this->resolveImageTokenCost($metadata, $options);
        $images = [];
        foreach ($references as $imageIndex => $reference) {
            $resolved = $this->resolveImage($reference['url']);
            $images[] = new RuntimeImageInput(
                id: 'image-' . $imageIndex,
                mimeType: $resolved->mimeType,
                bytes: $resolved->bytes,
                metadata: [
                    'source' => $reference['url'],
                    'messageIndex' => $reference['messageIndex'],
                    'blockIndex' => $reference['blockIndex'],
                    'imageIndex' => $imageIndex,
                    'tokenEstimate' => $imageTokenCost,
                ],
            );
        }

        $projectorPath = $this->projectorResolver->resolve($metadata, $options);

        return new LlamaCppMultimodalContext(
            images: $images,
            requestOptions: [
                'projectorPath' => $projectorPath,
                'imageCount' => count($images),
                'imageTokenEstimate' => count($images) * $imageTokenCost,
            ],
        );
    }

    /**
     * Pass 1: find every image reference without reading any of them.
     *
     * @param MessageInterface[] $messages
     * @return list<array{url: string, messageIndex: int|string, blockIndex: int|string}>
     */
    private function collectImageReferences(array $messages): array
    {
        $references = [];
        foreach ($messages as $messageIndex => $message) {
            $content = $message->content();
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $blockIndex => $block) {
                if (($block['type'] ?? null) !== 'image_url') {
                    continue;
                }

                $url = $this->extractImageUrl($block);
                if ($url !== null) {
                    $references[] = ['url' => $url, 'messageIndex' => $messageIndex, 'blockIndex' => $blockIndex];
                }
            }
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractImageUrl(array $block): ?string
    {
        $imageData = $block['image_url'] ?? null;
        if (is_array($imageData)) {
            $url = $imageData['url'] ?? null;

            return is_string($url) && $url !== '' ? $url : null;
        }

        return is_string($imageData) && $imageData !== '' ? $imageData : null;
    }

    /**
     * Pass 2: read one reference. Exception messages never carry image bytes or data URI contents.
     */
    private function resolveImage(string $url): ResolvedImage
    {
        if (str_starts_with(strtolower($url), 'data:')) {
            return $this->decodeDataUri($url);
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            if ($this->imageResolver !== null) {
                return $this->checked($this->imageResolver->resolve($url));
            }
            if ($this->remoteImageFetcher !== null) {
                $bytes = ($this->remoteImageFetcher)($url);
                if (!is_string($bytes)) {
                    throw new ImageInputException('The remote image fetcher did not return image bytes.');
                }

                return new ResolvedImage($this->imagePolicy->check($bytes), $bytes);
            }

            throw new ImageInputException('Remote image URLs are disabled; pass a data: URI or configure an ImageResolverInterface.');
        }

        if ($this->imageResolver !== null) {
            return $this->checked($this->imageResolver->resolve($url));
        }

        throw new ImageInputException('Local image paths are disabled; pass a data: URI or configure an ImageResolverInterface.');
    }

    /** A resolver's image re-checked against this normalizer's policy; the sniffed MIME type wins. */
    private function checked(ResolvedImage $image): ResolvedImage
    {
        return new ResolvedImage($this->imagePolicy->check($image->bytes, $image->mimeType), $image->bytes);
    }

    private function decodeDataUri(string $url): ResolvedImage
    {
        if (preg_match('#^data:[^,]*;base64,#i', $url, $matches) !== 1) {
            throw new ImageInputException('Image data URI must be base64 encoded.');
        }

        $encoded = substr($url, strlen($matches[0]));
        $maxEncoded = (int) ceil($this->imagePolicy->maxBytes * 4 / 3) + 4;
        if (strlen($encoded) > $maxEncoded) {
            throw new ImageInputException(sprintf('Image data URI is larger than %d bytes.', $this->imagePolicy->maxBytes));
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new ImageInputException('Invalid base64 image data.');
        }

        return new ResolvedImage($this->imagePolicy->check($bytes), $bytes);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveMaxImages(RuntimeModelMetadata $metadata, array $options): int
    {
        $maxImages = $options['maxImages'] ?? $metadata->extras['maxImages'] ?? 1;

        return max(1, (int) $maxImages);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveImageTokenCost(RuntimeModelMetadata $metadata, array $options): int
    {
        $imageTokenCost = $options['imageTokenCost'] ?? $metadata->extras['imageTokenCost'] ?? 1024;

        return max(1, (int) $imageTokenCost);
    }
}
