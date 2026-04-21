<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Native provider for Google Gemini models.
 *
 * Uses Gemini's native REST API (not the OpenAI-compatible endpoint) for
 * full access to Gemini-specific features: inlineData images, functionDeclarations
 * tool calling, Content/Part message format, and structured output via
 * response_mime_type + response_schema.
 *
 * Endpoint pattern: POST {baseUrl}/models/{model}:generateContent
 * Auth: x-goog-api-key header (not Bearer token)
 *
 * Scope: Core chat + tools + base64 images via inlineData.
 * Future: video input, File API (fileData), grounding, code execution.
 */
final class GeminiProvider extends AbstractProvider
{
    /** JSON Schema keywords unsupported by Gemini. */
    private const UNSUPPORTED_KEYWORDS = [
        'additionalProperties',
        '$schema',
        '$ref',
        '$defs',
        'default',
    ];

    public function __construct(
        string $model = 'gemini-2.5-flash',
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
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

    #[\Override]
    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $this->apiKey,
        ];
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $payload = $this->buildPayload($messages, $tools, $options);

        $response = $this->httpClient->request(
            'POST',
            "{$this->baseUrl}/models/{$this->model}:generateContent",
            [
                'headers' => $this->headers(),
                'json' => $payload,
            ],
        );

        return $this->parseResponse($response->toArray());
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $payload = $this->buildPayload($messages, $tools, $options);

        $response = $this->httpClient->request(
            'POST',
            "{$this->baseUrl}/models/{$this->model}:streamGenerateContent?alt=sse",
            [
                'headers' => $this->headers(),
                'json' => $payload,
            ],
        );

        $parser = new SseStreamParser($this->httpClient, $response);
        $toolCallCounter = 0;

        foreach ($parser->events() as $json) {
            $candidate = $json['candidates'][0] ?? [];
            $parts = $candidate['content']['parts'] ?? [];
            $finishReason = $this->mapGeminiFinishReason($candidate['finishReason'] ?? null);

            $text = '';
            $reasoning = '';
            $toolCalls = [];

            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    // Thought parts (thinkingConfig.includeThoughts = true) have thought: true
                    if (($part['thought'] ?? false) === true) {
                        $reasoning .= $part['text'];
                    } else {
                        $text .= $part['text'];
                    }
                } elseif (isset($part['functionCall'])) {
                    $name = $part['functionCall']['name'];
                    $metadata = [];
                    if (isset($part['thoughtSignature'])) {
                        $metadata['thoughtSignature'] = $part['thoughtSignature'];
                    }
                    $toolCalls[] = new ToolCall(
                        id: sprintf('g%08x', $toolCallCounter++),
                        name: $name,
                        arguments: $part['functionCall']['args'] ?? [],
                        metadata: $metadata,
                    );
                }
            }

            // Yield reasoning separately so the agent can emit agent.reasoning events
            if ($reasoning !== '') {
                yield new Response(
                    content: '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $this->model,
                    reasoning: $reasoning,
                );
            }

            if (!empty($toolCalls)) {
                yield new Response(
                    content: $text,
                    finishReason: ProviderFinishReason::ToolUse,
                    toolCalls: $toolCalls,
                    model: $this->model,
                    usage: $this->parseUsageMetadata($json),
                );
                continue;
            }

            if ($text !== '' || $finishReason !== ProviderFinishReason::Stop) {
                yield new Response(
                    content: $text,
                    finishReason: $finishReason,
                    toolCalls: [],
                    model: $this->model,
                    usage: $this->parseUsageMetadata($json),
                );
            }
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $schemaData = json_decode($schema, true);
        if ($schemaData === null) {
            return $this->chat($messages, [], $options);
        }

        // Use generationConfig with response_mime_type for structured output
        $responseSchema = $schemaData['schema'] ?? $schemaData['parameters'] ?? $schemaData;

        // Strip unsupported schema fields for Gemini
        unset($responseSchema['name'], $responseSchema['description']);
        if (!isset($responseSchema['type'])) {
            $responseSchema['type'] = 'OBJECT';
        } else {
            $responseSchema['type'] = strtoupper($responseSchema['type']);
        }

        $options['generationConfig'] = array_merge(
            $options['generationConfig'] ?? [],
            [
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        );

        return $this->chat($messages, [], $options);
    }

    /**
     * List available models from the Gemini API.
     *
     * @return ModelDefinition[]
     */
    public function models(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", [
                'headers' => $this->headers(),
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            $models = [];

            foreach ($data['models'] ?? [] as $model) {
                // Only include models that support generateContent
                $methods = $model['supportedGenerationMethods'] ?? [];
                if (!in_array('generateContent', $methods, true)) {
                    continue;
                }

                $id = $model['name'] ?? '';
                // Strip "models/" prefix: "models/gemini-2.5-flash" → "gemini-2.5-flash"
                if (str_starts_with($id, 'models/')) {
                    $id = substr($id, 7);
                }

                $models[] = new ModelDefinition(
                    id: $id,
                    name: $model['displayName'] ?? $id,
                    provider: 'gemini',
                    contextWindow: $model['inputTokenLimit'] ?? 1048576,
                    maxTokens: $model['outputTokenLimit'] ?? 8192,
                    family: 'gemini',
                    metadataSource: 'provider-api',
                    fieldSources: [
                        'contextWindow' => 'provider-api',
                        'maxTokens' => 'provider-api',
                    ],
                );
            }

            return $models;
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch Gemini models: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function isAvailable(): bool
    {
        if ($this->apiKey === '') {
            return false;
        }

        try {
            $this->httpClient->request('GET', "{$this->baseUrl}/models", [
                'headers' => $this->headers(),
                'timeout' => 5,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->debug('Gemini availability check failed: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Build the request payload for Gemini's generateContent endpoint.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $tools, array $options): array
    {
        [$systemInstruction, $contents] = $this->extractSystemAndContents($messages);

        $payload = ['contents' => $contents];

        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        // Merge generationConfig from options
        if (isset($options['generationConfig'])) {
            $payload['generationConfig'] = $options['generationConfig'];
            unset($options['generationConfig']);
        }

        // Pass through temperature, maxOutputTokens, etc. into generationConfig
        $genConfigKeys = ['temperature', 'topP', 'topK', 'maxOutputTokens', 'stopSequences'];
        foreach ($genConfigKeys as $key) {
            if (isset($options[$key])) {
                $payload['generationConfig'][$key] = $options[$key];
                unset($options[$key]);
            }
        }

        // Merge any remaining options at top level
        return array_merge($payload, $options);
    }

    /**
     * Extract system instruction and convert messages to Gemini Content format.
     *
     * @param MessageInterface[] $messages
     * @return array{0: ?array<string, mixed>, 1: array<array<string, mixed>>}
     */
    private function extractSystemAndContents(array $messages): array
    {
        $systemInstruction = null;
        $contents = [];
        /** @var array<string, string> $callIdToName Maps tool call ID → function name */
        $callIdToName = [];

        foreach ($messages as $message) {
            if ($message->role() === Role::System) {
                $content = $message->content();
                $text = is_string($content) ? $content : (json_encode($content) ?: '');
                if ($text !== '') {
                    $systemInstruction = ['parts' => [['text' => $text]]];
                }
                continue;
            }

            // Track tool call IDs → function names from assistant messages
            if ($message->role() === Role::Assistant && !empty($message->toolCalls())) {
                foreach ($message->toolCalls() as $toolCall) {
                    $callIdToName[$toolCall->id] = $toolCall->name;
                }
            }

            $contents[] = $this->formatGeminiContent($message, $callIdToName);
        }

        // Merge consecutive same-role contents (Gemini requires role alternation)
        /** @var array<int, array{role: string, parts: array<array<string, mixed>>}> $merged */
        $merged = [];
        foreach ($contents as $content) {
            $last = end($merged);
            $lastKey = array_key_last($merged);
            if (
                $last !== false &&
                $lastKey !== null &&
                isset($last['role'], $content['role']) &&
                $last['role'] === $content['role']
            ) {
                $merged[$lastKey]['parts'] = array_merge($last['parts'], $content['parts']);
            } else {
                $merged[] = $content;
            }
        }

        return [$systemInstruction, $merged];
    }

    /**
     * Convert a single MessageInterface to Gemini Content format.
     *
     * @param array<string, string> $callIdToName Maps tool call ID → function name
     * @return array{role: string, parts: array<array<string, mixed>>}
     */
    private function formatGeminiContent(MessageInterface $message, array $callIdToName = []): array
    {
        $role = match ($message->role()) {
            Role::User => 'user',
            Role::Assistant => 'model',
            Role::Tool => 'user',
            default => 'user',
        };

        // Tool result messages → functionResponse parts
        if ($message->role() === Role::Tool) {
            $toolCallId = $message->toolCallId() ?? '';
            // Gemini expects the actual function name, not the call ID
            $functionName = $callIdToName[$toolCallId] ?? $toolCallId;
            $content = $message->content();
            $responseData = is_string($content) ? ['result' => $content] : $content;

            return [
                'role' => 'user',
                'parts' => [
                    [
                        'functionResponse' => [
                            'name' => $functionName,
                            'response' => $responseData,
                        ],
                    ],
                ],
            ];
        }

        // Assistant messages with tool calls → functionCall parts
        if ($message->role() === Role::Assistant && !empty($message->toolCalls())) {
            $parts = [];
            $text = $message->content();
            if (is_string($text) && $text !== '') {
                $parts[] = ['text' => $text];
            }
            foreach ($message->toolCalls() as $toolCall) {
                $part = [
                    'functionCall' => [
                        'name' => $toolCall->name,
                        'args' => !empty($toolCall->arguments) ? $toolCall->arguments : (object) [],
                    ],
                ];
                // Gemini 3 requires thoughtSignature on functionCall parts.
                // Use real signature if available, otherwise dummy to skip validation.
                if (isset($toolCall->metadata['thoughtSignature'])) {
                    $part['thoughtSignature'] = $toolCall->metadata['thoughtSignature'];
                } else {
                    $part['thoughtSignature'] = 'skip_thought_signature_validator';
                }
                $parts[] = $part;
            }

            return ['role' => 'model', 'parts' => $parts];
        }

        // Regular messages → text and/or inlineData parts
        return [
            'role' => $role,
            'parts' => $this->convertContentToParts($message->content()),
        ];
    }

    /**
     * Convert message content to Gemini parts array.
     *
     * Handles string content, text blocks, and OpenAI-format image_url blocks
     * (converting them to Gemini's inlineData format).
     *
     * @param string|array<array<string, mixed>> $content
     * @return array<array<string, mixed>>
     */
    private function convertContentToParts(string|array $content): array
    {
        if (is_string($content)) {
            return $content !== '' ? [['text' => $content]] : [];
        }

        $parts = [];
        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $parts[] = ['text' => $block['text'] ?? ''];
            } elseif ($type === 'image_url') {
                $parts[] = $this->convertImageToPart($block);
            } else {
                // Unknown block types — try to extract text
                if (isset($block['text'])) {
                    $parts[] = ['text' => $block['text']];
                }
            }
        }

        return $parts;
    }

    /**
     * Convert an OpenAI-format image_url block to a Gemini inlineData part.
     *
     * Gemini's inlineData only accepts base64. For data URIs we extract the
     * data directly. For HTTP(S) URLs we download the image and base64-encode
     * it (Gemini has no native URL reference support — the File API is the
     * official mechanism, but downloading + inlineData works for typical sizes).
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function convertImageToPart(array $block): array
    {
        $imageData = $block['image_url'] ?? [];
        $url = is_array($imageData) ? ($imageData['url'] ?? '') : (string) $imageData;

        // Parse data URI: data:{media_type};base64,{data}
        if (preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches)) {
            return [
                'inlineData' => [
                    'mimeType' => $matches[1],
                    'data' => $matches[2],
                ],
            ];
        }

        // URL-based images — download and convert to inlineData
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $downloaded = $this->downloadImageForInlineData($url);
            if ($downloaded !== null) {
                return $downloaded;
            }

            // Fallback: include URL as text so the model at least knows an image was intended
            return [
                'text' => "[Image: could not download {$url} — provide a base64 data URI instead]",
            ];
        }

        // Unknown format — pass as text reference
        return [
            'text' => "[Image reference: {$url}]",
        ];
    }

    /**
     * Download an image URL and return a Gemini inlineData part.
     *
     * @return array{inlineData: array{mimeType: string, data: string}}|null
     */
    private function downloadImageForInlineData(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 30,
                'max_redirects' => 5,
                'headers' => [
                    'Accept' => 'image/*',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                return null;
            }

            $headers = $response->getHeaders();
            $contentType = $headers['content-type'][0] ?? 'image/jpeg';
            $mime = trim(explode(';', $contentType)[0]);

            // Validate it looks like an image
            if (!str_starts_with($mime, 'image/')) {
                $mime = $this->guessMimeFromUrl($url) ?? 'image/jpeg';
            }

            $body = $response->getContent();
            if ($body === '') {
                return null;
            }

            return [
                'inlineData' => [
                    'mimeType' => $mime,
                    'data' => base64_encode($body),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to download image from URL: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function guessMimeFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    /**
     * Format tools for Gemini's functionDeclarations format.
     *
     * Gemini expects: [{functionDeclarations: [{name, description, parameters}]}]
     *
     * @param ToolInterface[] $tools
     * @return array<array<string, mixed>>
     */
    protected function formatTools(array $tools): array
    {
        $declarations = [];

        foreach ($tools as $tool) {
            $schema = $tool->toFunctionSchema();
            $function = $schema['function'] ?? $schema;

            $declaration = [
                'name' => $function['name'] ?? '',
                'description' => $function['description'] ?? '',
            ];

            if (isset($function['parameters'])) {
                $declaration['parameters'] = $this->normalizeSchemaForGemini(
                    $function['parameters'],
                );
            }

            $declarations[] = $declaration;
        }

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Normalize JSON Schema for Gemini compatibility.
     *
     * Gemini expects uppercase type names (STRING, NUMBER, OBJECT, etc.)
     * and doesn't support some JSON Schema keywords.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeSchemaForGemini(array $schema): array
    {
        // Convert type to uppercase (Gemini requirement)
        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = strtoupper($schema['type']);
        }

        // Recurse into properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = $this->normalizeSchemaForGemini($property);
                }
            }
        }

        // Recurse into items
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->normalizeSchemaForGemini($schema['items']);
        }

        return SchemaUtils::stripKeywords($schema, self::UNSUPPORTED_KEYWORDS);
    }

    /**
     * Format messages — delegates to extractSystemAndContents.
     *
     * @param MessageInterface[] $messages
     * @return array<array<string, mixed>>
     */
    protected function formatMessages(array $messages): array
    {
        [, $contents] = $this->extractSystemAndContents($messages);

        return $contents;
    }

    /**
     * Parse Gemini API response into a Response value object.
     *
     * @param array<string, mixed> $data
     */
    protected function parseResponse(array $data): Response
    {
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $content = '';
        $reasoning = '';
        $toolCalls = [];

        $callIndex = 0;
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                // Thought parts (thinkingConfig.includeThoughts = true) have thought: true
                if (($part['thought'] ?? false) === true) {
                    $reasoning .= $part['text'];
                } else {
                    $content .= $part['text'];
                }
            } elseif (isset($part['functionCall'])) {
                $name = $part['functionCall']['name'];
                $metadata = [];
                if (isset($part['thoughtSignature'])) {
                    $metadata['thoughtSignature'] = $part['thoughtSignature'];
                }
                $toolCalls[] = new ToolCall(
                    id: sprintf('g%08x', $callIndex++),
                    name: $name,
                    arguments: $part['functionCall']['args'] ?? [],
                    metadata: $metadata,
                );
            }
        }

        $finishReason = $this->mapGeminiFinishReason($candidate['finishReason'] ?? null);

        // Override finish reason if tool calls are present
        if (!empty($toolCalls)) {
            $finishReason = ProviderFinishReason::ToolUse;
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $this->model,
            usage: $this->parseUsageMetadata($data),
            reasoning: $reasoning,
        );
    }

    /**
     * Map Gemini finish reasons to ProviderFinishReason enum.
     */
    private function mapGeminiFinishReason(?string $reason): ProviderFinishReason
    {
        return match ($reason) {
            'STOP' => ProviderFinishReason::Stop,
            'MAX_TOKENS' => ProviderFinishReason::MaxTokens,
            'SAFETY' => ProviderFinishReason::Error,
            'RECITATION' => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
    }

    /**
     * Parse usage metadata from Gemini response.
     *
     * @param array<string, mixed> $data
     */
    private function parseUsageMetadata(array $data): ?Usage
    {
        $usage = $data['usageMetadata'] ?? null;
        if ($usage === null) {
            return null;
        }

        $prompt = $usage['promptTokenCount'] ?? 0;
        $completion = $usage['candidatesTokenCount'] ?? 0;
        $total = $usage['totalTokenCount'] ?? ($prompt + $completion);

        return new Usage(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
        );
    }
}
