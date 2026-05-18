<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

final class LlamaCppMultimodalNormalizer
{
    private readonly ?\Closure $remoteImageFetcher;

    /**
     * @param null|callable(string):string $remoteImageFetcher
     */
    public function __construct(
        private readonly LlamaCppProjectorResolver $projectorResolver,
        null|callable $remoteImageFetcher = null,
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
        $images = [];
        $imageTokenCost = $this->resolveImageTokenCost($metadata, $options);

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
                if ($url === null) {
                    continue;
                }

                $resolved = $this->resolveImage($url);
                $images[] = new RuntimeImageInput(
                    id: 'image-' . count($images),
                    mimeType: $resolved['mimeType'],
                    bytes: $resolved['bytes'],
                    metadata: [
                        'source' => $url,
                        'messageIndex' => $messageIndex,
                        'blockIndex' => $blockIndex,
                        'imageIndex' => count($images),
                        'tokenEstimate' => $imageTokenCost,
                    ],
                );
            }
        }

        if ($images === []) {
            return new LlamaCppMultimodalContext([], []);
        }

        if (!$metadata->supportsVision) {
            throw new \InvalidArgumentException("Model {$metadata->id} does not support image input.");
        }

        $maxImages = $this->resolveMaxImages($metadata, $options);
        if (count($images) > $maxImages) {
            throw new \InvalidArgumentException(
                "Model {$metadata->id} supports at most {$maxImages} image(s) per request.",
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
     * @return array{mimeType: string, bytes: string}
     */
    private function resolveImage(string $url): array
    {
        if (preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches) === 1) {
            $bytes = base64_decode($matches[2], true);
            if ($bytes === false) {
                throw new \InvalidArgumentException('Invalid base64 image data.');
            }

            return [
                'mimeType' => $matches[1],
                'bytes' => $bytes,
            ];
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            $fetcher = $this->remoteImageFetcher ?? static fn(string $remoteUrl): string => (string) file_get_contents($remoteUrl);
            $bytes = $fetcher($url);
            if ($bytes === '') {
                throw new \RuntimeException("Failed to download image input: {$url}");
            }

            $remotePath = parse_url($url, PHP_URL_PATH);

            return [
                'mimeType' => is_string($remotePath)
                    ? $this->inferMimeTypeFromPath($remotePath)
                    : 'application/octet-stream',
                'bytes' => $bytes,
            ];
        }

        $path = str_starts_with($url, 'file://') ? (string) parse_url($url, PHP_URL_PATH) : $url;
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to read image input: {$url}");
        }

        return [
            'mimeType' => $this->inferMimeTypeFromPath($path),
            'bytes' => $bytes,
        ];
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

    private function inferMimeTypeFromPath(null|string|int $path): string
    {
        if (!is_string($path) || $path === '') {
            return 'application/octet-stream';
        }

        if (is_file($path)) {
            $mimeType = mime_content_type($path);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }

        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}