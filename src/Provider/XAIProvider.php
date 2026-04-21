<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Provider for xAI (Grok) models.
 *
 * xAI's API is OpenAI-compatible for text and tool calling, but uses
 * different content type names for vision:
 *   - "text"      → "input_text"
 *   - "image_url" → "input_image"
 *
 * This provider overrides formatMessages() to perform the conversion,
 * ensuring vision content blocks reach xAI in the expected format.
 */
final class XAIProvider extends OpenAICompatibleProvider
{
    public function __construct(
        string $model,
        string $baseUrl = 'https://api.x.ai/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            logger: $logger,
            discoveredProviderName: 'xai',
        );
    }

    /**
     * Format messages with xAI-specific vision content types.
     *
     * Walks each message's content blocks and converts:
     *   - {type: "text", text: "..."} → {type: "input_text", text: "..."}
     *   - {type: "image_url", image_url: {...}} → {type: "input_image", image_url: ...}
     *
     * String content and messages without content arrays pass through unchanged.
     * The image_url key is preserved — xAI reuses that field name.
     *
     * @param MessageInterface[] $messages
     * @return array<array<string, mixed>>
     */
    #[\Override]
    protected function formatMessages(array $messages): array
    {
        return array_map(function (MessageInterface $msg): array {
            $data = $msg->toArray();

            if (!isset($data['content']) || !is_array($data['content'])) {
                return $data;
            }

            $data['content'] = array_map(
                fn(array $block): array => $this->convertContentBlock($block),
                $data['content'],
            );

            return $data;
        }, $messages);
    }

    /**
     * Convert a single content block to xAI format.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function convertContentBlock(array $block): array
    {
        $type = $block['type'] ?? '';

        return match ($type) {
            'text' => [...$block, 'type' => 'input_text'],
            'image_url' => $this->convertImageBlock($block),
            default => $block,
        };
    }

    /**
     * Convert an OpenAI-format image_url block to xAI's input_image format.
     *
     * xAI expects:
     *   {type: "input_image", image_url: "<url_string>", detail: "high"|"low"}
     *
     * OpenAI sends:
     *   {type: "image_url", image_url: {url: "<url_string>", detail: "..."}}
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function convertImageBlock(array $block): array
    {
        $imageData = $block['image_url'] ?? [];
        $url = is_array($imageData) ? ($imageData['url'] ?? '') : (string) $imageData;
        $detail = is_array($imageData) ? ($imageData['detail'] ?? 'high') : 'high';

        return [
            'type' => 'input_image',
            'image_url' => $url,
            'detail' => $detail,
        ];
    }
}
