<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnthropicProvider extends AbstractProvider
{
    private string $apiVersion = '2023-06-01';

    public function __construct(
        string $model = 'claude-sonnet-4-20250514',
        string $baseUrl = 'https://api.anthropic.com/v1',
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

    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
        ];
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        [$systemPrompt, $formattedMessages] = $this->extractSystemAndMessages($messages);

        $payload = [
            'model' => $this->model,
            'messages' => $formattedMessages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        unset($options['max_tokens']);
        $payload = [...$payload, ...$options];

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        return $this->parseResponse($response->toArray());
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        [$systemPrompt, $formattedMessages] = $this->extractSystemAndMessages($messages);

        $payload = [
            'model' => $this->model,
            'messages' => $formattedMessages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'stream' => true,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        // Accumulate tool use blocks across stream events. Anthropic sends:
        //   content_block_start  → type: tool_use with id + name
        //   content_block_delta  → type: input_json_delta with partial JSON
        //   content_block_stop   → block finalized
        //   message_delta        → stop_reason: tool_use when all blocks done
        /** @var array<int, array{id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];
        $currentBlockIndex = -1;
        $currentBlockType = '';

        // Buffer incomplete SSE lines across HTTP chunks.
        $lineBuffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $data = $lineBuffer . $chunk->getContent();
            $lineBuffer = '';

            $lines = explode("\n", $data);

            if (!str_ends_with($data, "\n")) {
                $lineBuffer = array_pop($lines);
            }

            foreach ($lines as $line) {
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = json_decode(substr($line, 6), true);
                if ($json === null || !isset($json['type'])) {
                    continue;
                }

                $eventType = $json['type'];

                if ($eventType === 'content_block_start') {
                    $currentBlockIndex = $json['index'] ?? 0;
                    $block = $json['content_block'] ?? [];
                    $currentBlockType = $block['type'] ?? 'text';

                    if ($currentBlockType === 'tool_use') {
                        $pendingToolCalls[$currentBlockIndex] = [
                            'id' => $block['id'] ?? '',
                            'name' => $block['name'] ?? '',
                            'arguments' => '',
                        ];
                    }
                } elseif ($eventType === 'content_block_delta') {
                    $delta = $json['delta'] ?? [];
                    $index = $json['index'] ?? $currentBlockIndex;
                    $deltaType = $delta['type'] ?? '';

                    if ($deltaType === 'input_json_delta' && isset($pendingToolCalls[$index])) {
                        $pendingToolCalls[$index]['arguments'] .= $delta['partial_json'] ?? '';
                    } elseif ($deltaType === 'text_delta' && isset($delta['text'])) {
                        yield new Response(
                            content: $delta['text'],
                            finishReason: FinishReason::Stop,
                            toolCalls: [],
                            model: $this->model,
                        );
                    }
                } elseif ($eventType === 'message_delta') {
                    $stopReason = $json['delta']['stop_reason'] ?? null;

                    $usage = null;
                    if (isset($json['usage'])) {
                        $usage = new Usage(
                            promptTokens: 0,
                            completionTokens: $json['usage']['output_tokens'] ?? 0,
                            totalTokens: $json['usage']['output_tokens'] ?? 0,
                        );
                    }

                    if ($stopReason === 'tool_use' && !empty($pendingToolCalls)) {
                        $toolCalls = [];
                        foreach ($pendingToolCalls as $tc) {
                            $toolCalls[] = new ToolCall(
                                id: $tc['id'],
                                name: $tc['name'],
                                arguments: json_decode($tc['arguments'], true) ?? [],
                            );
                        }
                        $pendingToolCalls = [];

                        yield new Response(
                            content: '',
                            finishReason: FinishReason::ToolUse,
                            toolCalls: $toolCalls,
                            model: $this->model,
                            usage: $usage,
                        );
                    } elseif ($stopReason === 'end_turn' || $stopReason === 'max_tokens') {
                        yield new Response(
                            content: '',
                            finishReason: $stopReason === 'max_tokens' ? FinishReason::MaxTokens : FinishReason::Stop,
                            toolCalls: [],
                            model: $this->model,
                            usage: $usage,
                        );
                    }
                } elseif ($eventType === 'message_start' && isset($json['message']['usage'])) {
                    // Initial usage data (input tokens)
                    yield new Response(
                        content: '',
                        finishReason: FinishReason::Stop,
                        toolCalls: [],
                        model: $json['message']['model'] ?? $this->model,
                        usage: new Usage(
                            promptTokens: $json['message']['usage']['input_tokens'] ?? 0,
                            completionTokens: 0,
                            totalTokens: $json['message']['usage']['input_tokens'] ?? 0,
                        ),
                    );
                }
            }
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        // Use Anthropic's tool_use trick: define the schema as a tool,
        // force the model to call it, then extract the structured data.
        $schemaData = json_decode($schema, true);
        if ($schemaData === null) {
            return $this->chat($messages, [], $options);
        }

        $toolName = $schemaData['name'] ?? 'structured_output';
        $description = $schemaData['description'] ?? 'Generate structured output matching the schema.';
        $inputSchema = $schemaData['schema'] ?? $schemaData['parameters'] ?? $schemaData;

        // Ensure the schema has type: object at root
        if (!isset($inputSchema['type'])) {
            $inputSchema['type'] = 'object';
        }

        $payload = $this->buildBasePayload($messages, $options);
        $payload['tools'] = [
            [
                'name' => $toolName,
                'description' => $description,
                'input_schema' => $inputSchema,
            ],
        ];
        $payload['tool_choice'] = ['type' => 'tool', 'name' => $toolName];

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        $data = $response->toArray();

        // Extract the structured data from the tool_use content block
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $toolName) {
                return $block['input'] ?? [];
            }
        }

        // Fallback: return the full parsed response
        return $this->parseResponse($data);
    }

    /**
     * Build the base payload for Anthropic API requests.
     *
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildBasePayload(array $messages, array $options = []): array
    {
        [$systemPrompt, $formattedMessages] = $this->extractSystemAndMessages($messages);

        $payload = [
            'model' => $this->model,
            'messages' => $formattedMessages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        unset($options['max_tokens']);
        return [...$payload, ...$options];
    }

    public function models(): array
    {
        // Attempt to fetch from Anthropic's models API (available since 2024)
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", [
                'headers' => $this->headers(),
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            $models = [];

            foreach ($data['data'] ?? [] as $model) {
                $models[] = new ModelDefinition(
                    id: $model['id'] ?? '',
                    name: $model['display_name'] ?? $model['id'] ?? '',
                    provider: 'anthropic',
                    contextWindow: $model['context_window'] ?? 200000,
                    maxTokens: $model['max_output'] ?? 4096,
                );
            }

            if (!empty($models)) {
                return $models;
            }
        } catch (\Throwable) {
            // Fall through to static list
        }

        // Static fallback — kept up-to-date with major releases
        return [
            new ModelDefinition(id: 'claude-opus-4-20250514', name: 'Claude Opus 4', provider: 'anthropic', contextWindow: 200000, maxTokens: 32000),
            new ModelDefinition(id: 'claude-sonnet-4-20250514', name: 'Claude Sonnet 4', provider: 'anthropic', contextWindow: 200000, maxTokens: 16000),
            new ModelDefinition(id: 'claude-3-5-sonnet-20241022', name: 'Claude 3.5 Sonnet', provider: 'anthropic', contextWindow: 200000, maxTokens: 8192),
            new ModelDefinition(id: 'claude-3-5-haiku-20241022', name: 'Claude 3.5 Haiku', provider: 'anthropic', contextWindow: 200000, maxTokens: 8192),
            new ModelDefinition(id: 'claude-3-opus-20240229', name: 'Claude 3 Opus', provider: 'anthropic', contextWindow: 200000, maxTokens: 4096),
            new ModelDefinition(id: 'claude-3-haiku-20240307', name: 'Claude 3 Haiku', provider: 'anthropic', contextWindow: 200000, maxTokens: 4096),
        ];
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
        } catch (\Throwable) {
            return false;
        }
    }

    protected function formatTools(array $tools): array
    {
        return array_map(function (ToolInterface $tool) {
            $schema = $tool->toFunctionSchema();

            return [
                'name' => $schema['function']['name'],
                'description' => $schema['function']['description'],
                'input_schema' => $schema['function']['parameters'],
            ];
        }, $tools);
    }

    protected function formatMessages(array $messages): array
    {
        return array_map(fn(MessageInterface $msg) => $msg->toArray(), $messages);
    }

    /**
     * @param MessageInterface[] $messages
     * @return array{0: string, 1: array<array<string, mixed>>}
     */
    private function extractSystemAndMessages(array $messages): array
    {
        $systemPrompt = '';
        $formatted = [];

        foreach ($messages as $message) {
            if ($message->role() === Role::System) {
                $content = $message->content();
                $systemPrompt = (is_string($content) ? $content : (json_encode($content) ?: ''));
                continue;
            }

            $formatted[] = $this->formatAnthropicMessage($message);
        }

        // Merge consecutive same-role messages (required by Anthropic).
        // Consecutive tool_result user messages must be combined into a single
        // user message with multiple content blocks. Handle mixed content types
        // (string + array) by normalizing strings to text content blocks.
        $merged = [];
        foreach ($formatted as $msg) {
            $last = end($merged);
            $lastKey = array_key_last($merged);
            if ($last !== false && $lastKey !== null && $last['role'] === $msg['role']) {
                $lastContent = $this->normalizeContent($last['content']);
                $msgContent = $this->normalizeContent($msg['content']);
                $merged[$lastKey]['content'] = array_merge($lastContent, $msgContent);
            } else {
                $merged[] = $msg;
            }
        }

        return [$systemPrompt, $merged];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAnthropicMessage(MessageInterface $message): array
    {
        $role = match ($message->role()) {
            Role::User => 'user',
            Role::Assistant => 'assistant',
            Role::Tool => 'user',
            default => 'user',
        };

        // Tool result messages → user message with tool_result content block
        if ($message->role() === Role::Tool) {
            $toolCallId = $message->toolCallId();

            // Anthropic requires a non-null tool_use_id. Generate a fallback
            // for replayed conversations where the ID was not persisted.
            if ($toolCallId === null || $toolCallId === '') {
                $toolCallId = 'toolu_' . bin2hex(random_bytes(12));
            }

            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolCallId,
                        'content' => $message->content(),
                    ],
                ],
            ];
        }

        // Assistant messages with tool calls → content blocks with tool_use
        if ($message->role() === Role::Assistant && !empty($message->toolCalls())) {
            $content = [];
            $text = $message->content();
            if (is_string($text) && $text !== '') {
                $content[] = ['type' => 'text', 'text' => $text];
            }
            foreach ($message->toolCalls() as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => !empty($toolCall->arguments) ? $toolCall->arguments : (object) [],
                ];
            }

            return [
                'role' => 'assistant',
                'content' => $content,
            ];
        }

        return [
            'role' => $role,
            'content' => $this->convertContentForAnthropic($message->content()),
        ];
    }

    /**
     * Convert message content to Anthropic-compatible format.
     *
     * Transforms OpenAI-format image_url blocks (data URI with base64) into
     * Anthropic's image source format. Text blocks and plain strings pass
     * through unchanged.
     *
     * @param string|array<array<string, mixed>> $content
     * @return string|array<array<string, mixed>>
     */
    private function convertContentForAnthropic(string|array $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }

        $converted = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'image_url' && isset($block['image_url']['url'])) {
                $url = $block['image_url']['url'];

                // Parse data URI: data:{media_type};base64,{data}
                if (preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches)) {
                    $converted[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $matches[1],
                            'data' => $matches[2],
                        ],
                    ];
                } else {
                    // URL-based image (Anthropic supports url source type)
                    $converted[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => $url,
                        ],
                    ];
                }
            } else {
                // text blocks and others pass through
                $converted[] = $block;
            }
        }

        return $converted;
    }

    protected function parseResponse(array $data): Response
    {
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'] ?? '';
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'] ?? '',
                    name: $block['name'] ?? '',
                    arguments: $block['input'] ?? [],
                );
            }
        }

        $finishReason = match ($data['stop_reason'] ?? 'end_turn') {
            'end_turn' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolUse,
            'max_tokens' => FinishReason::MaxTokens,
            default => FinishReason::Stop,
        };

        $usage = null;
        if (isset($data['usage'])) {
            $usage = new Usage(
                promptTokens: $data['usage']['input_tokens'] ?? 0,
                completionTokens: $data['usage']['output_tokens'] ?? 0,
                totalTokens: ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
            );
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $usage,
        );
    }

    /**
     * Normalize message content to an array of content blocks.
     *
     * Anthropic requires content blocks when merging consecutive same-role
     * messages. Plain string content is converted to a text block.
     *
     * @param string|array<array<string, mixed>> $content
     * @return array<array<string, mixed>>
     */
    private function normalizeContent(string|array $content): array
    {
        if (is_string($content)) {
            return $content !== '' ? [['type' => 'text', 'text' => $content]] : [];
        }

        return $content;
    }
}
