<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Provider for Mistral AI models.
 *
 * Mistral's API is OpenAI-compatible with one notable divergence for vision:
 * image_url can be a flat string instead of a nested {url: "..."} object.
 * This provider normalizes OpenAI-format nested image_url objects to Mistral's
 * flat string format for maximum compatibility.
 *
 * All other functionality (chat, streaming, tools, structured output) uses
 * the standard OpenAI Chat Completions protocol unchanged.
 */
final class MistralProvider extends OpenAICompatibleProvider
{
    public function __construct(
        string $model = 'mistral-large-latest',
        string $baseUrl = 'https://api.mistral.ai/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
        );
    }

    /**
     * Format messages with Mistral-compatible image_url format.
     *
     * Walks each message's content blocks and flattens nested image_url objects:
     *   {type: "image_url", image_url: {url: "..."}} → {type: "image_url", image_url: "..."}
     *
     * Already-flat image_url strings and text blocks pass through unchanged.
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
                fn(array $block): array => $this->normalizeImageBlock($block),
                $data['content'],
            );

            return $data;
        }, $messages);
    }

    /**
     * Normalize an image_url content block for Mistral.
     *
     * Mistral accepts image_url as a direct string. If the block has a nested
     * object format (OpenAI style), flatten it to a string.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function normalizeImageBlock(array $block): array
    {
        if (($block['type'] ?? '') !== 'image_url') {
            return $block;
        }

        $imageData = $block['image_url'] ?? '';

        // Already a flat string — pass through
        if (is_string($imageData)) {
            return $block;
        }

        // Nested object {url: "...", detail: "..."} → flat string
        if (is_array($imageData) && isset($imageData['url'])) {
            return [
                'type' => 'image_url',
                'image_url' => $imageData['url'],
            ];
        }

        return $block;
    }
}
