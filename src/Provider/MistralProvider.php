<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Provider for Mistral AI models.
 *
 * Mistral's API is OpenAI-compatible with two notable divergences:
 *
 * 1. **Vision**: image_url can be a flat string instead of a nested {url: "..."} object.
 *    This provider normalizes OpenAI-format nested image_url objects to Mistral's
 *    flat string format for maximum compatibility.
 *
 * 2. **Tool call IDs**: Mistral requires tool_call_id to be exactly 9 alphanumeric
 *    characters ([a-zA-Z0-9]{9}). IDs from other providers (OpenAI's `call_*`,
 *    Anthropic's `toolu_*`, Gemini's synthetic IDs) are normalized to compliant
 *    9-character alphanumeric IDs during formatMessages(). The mapping is
 *    deterministic (hash-based) so retries produce identical IDs.
 *
 * All other functionality (chat, streaming, tools, structured output) uses
 * the standard OpenAI Chat Completions protocol unchanged.
 */
final class MistralProvider extends OpenAICompatibleProvider
{
    /** Mistral requires tool call IDs to match this pattern exactly. */
    private const TOOL_CALL_ID_PATTERN = '/^[a-zA-Z0-9]{9}$/';

    /** Characters used for generating compliant tool call IDs. */
    private const ID_CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

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
            discoveredProviderName: 'mistral',
        );
    }

    /**
     * Mistral's chat completions endpoint does not document OpenAI's
     * stream_options extension, so suppress it on inherited streaming calls.
     */
    #[\Override]
    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $options['stream_options'] = null;

        return parent::stream($messages, $tools, $options);
    }

    /**
     * Format messages with Mistral-compatible image_url and tool call ID formats.
     *
     * Applies two normalizations:
     * 1. Flattens nested image_url objects to Mistral's flat string format
     * 2. Normalizes tool call IDs to Mistral's required 9-character alphanumeric format
     *
     * Tool call ID normalization builds a mapping from original IDs to compliant IDs
     * while processing messages in order. Assistant messages with tool_calls have their
     * IDs remapped first, then tool result messages use the same mapping to maintain
     * correct pairing.
     *
     * @param MessageInterface[] $messages
     * @return array<array<string, mixed>>
     */
    #[\Override]
    protected function formatMessages(array $messages): array
    {
        /** @var array<string, string> $idMap original ID → Mistral-compliant ID */
        $idMap = [];

        $formatted = [];

        foreach ($messages as $msg) {
            $data = $msg->toArray();

            // Normalize image blocks in array content
            if (isset($data['content']) && is_array($data['content'])) {
                $data['content'] = array_map(
                    fn(array $block): array => $this->normalizeImageBlock($block),
                    $data['content'],
                );
            }

            // Normalize tool call IDs on assistant messages
            if (isset($data['tool_calls']) && is_array($data['tool_calls'])) {
                foreach ($data['tool_calls'] as &$tc) {
                    $originalId = $tc['id'] ?? '';
                    $tc['id'] = $this->normalizeToolCallId($originalId, $idMap);
                }
                unset($tc);
            }

            // Normalize tool_call_id on tool result messages
            if (isset($data['tool_call_id']) && is_string($data['tool_call_id'])) {
                $originalId = $data['tool_call_id'];
                $data['tool_call_id'] = $idMap[$originalId] ?? $this->normalizeToolCallId($originalId, $idMap);
            }

            $formatted[] = $data;
        }

        return $formatted;
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
     * Normalize a tool call ID to Mistral's required 9-character alphanumeric format.
     *
     * IDs already matching the format pass through unchanged. Non-conforming IDs
     * are deterministically mapped to a 9-character string derived from a hash
     * of the original ID. The mapping is stored in $idMap for consistent
     * remapping of assistant→tool result pairs.
     *
     * @param array<string, string> $idMap Mapping of original → normalized IDs (modified by reference)
     */
    private function normalizeToolCallId(string $originalId, array &$idMap): string
    {
        // Already mapped in a previous pass
        if (isset($idMap[$originalId])) {
            return $idMap[$originalId];
        }

        // Already compliant — pass through
        if (preg_match(self::TOOL_CALL_ID_PATTERN, $originalId) === 1) {
            $idMap[$originalId] = $originalId;

            return $originalId;
        }

        // Generate a deterministic 9-char alphanumeric ID from the original
        $hash = hash('sha256', $originalId);
        $charset = self::ID_CHARSET;
        $charsetLen = strlen($charset);
        $id = '';

        for ($i = 0; $i < 9; $i++) {
            // Use 2 hex chars (1 byte) per output character for good distribution
            $byte = hexdec(substr($hash, $i * 2, 2));
            $id .= $charset[$byte % $charsetLen];
        }

        $idMap[$originalId] = $id;

        return $id;
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
