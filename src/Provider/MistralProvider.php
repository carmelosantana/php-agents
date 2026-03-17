<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
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
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            logger: $logger,
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
     * Parse the response, handling Magistral models which return array content.
     *
     * Standard Mistral models return `message.content` as a string (handled by
     * the parent). Magistral models return it as an array of typed chunks:
     *   [{type: "thinking", thinking: [{type: "text", text: "..."}]}, {type: "text", text: "..."}]
     *
     * @param array<string, mixed> $data
     */
    #[\Override]
    protected function parseResponse(array $data): Response
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $messageContent = $message['content'] ?? '';

        // Standard string content — delegate to parent
        if (is_string($messageContent)) {
            return parent::parseResponse($data);
        }

        // Magistral array content — extract thinking and text blocks separately
        $content = '';
        $reasoning = '';
        $toolCalls = [];

        foreach ($messageContent as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'thinking') {
                // thinking value is an array of text chunks
                foreach ($block['thinking'] ?? [] as $chunk) {
                    $reasoning .= $chunk['text'] ?? '';
                }
            } elseif ($type === 'text') {
                $content .= $block['text'] ?? '';
            }
        }

        // Tool calls are still at the top level (same as standard Mistral)
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $arguments = $tc['function']['arguments'] ?? '{}';
            $toolCalls[] = new ToolCall(
                id: $tc['id'] ?? '',
                name: $tc['function']['name'] ?? '',
                arguments: json_decode($arguments, true) ?? [],
            );
        }

        $finishReason = $this->mapFinishReason($choice['finish_reason'] ?? 'stop');

        $usage = null;
        if (isset($data['usage'])) {
            $usage = new Usage(
                promptTokens: $data['usage']['prompt_tokens'] ?? 0,
                completionTokens: $data['usage']['completion_tokens'] ?? 0,
                totalTokens: $data['usage']['total_tokens'] ?? 0,
            );
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $usage,
            reasoning: $reasoning,
        );
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
