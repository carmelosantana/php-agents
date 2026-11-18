<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class AnthropicProvider extends AbstractProvider
{
    private string $apiVersion = '2023-06-01';

    public function __construct(
        string $model = 'claude-sonnet-4-20250514',
        string $baseUrl = 'https://api.anthropic.com/v1',
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
        $payload = $this->buildPayload($messages, $tools, $options);

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        $parsed = $this->parseResponse($this->decodeResponse($response));

        return new Response(
            content: $parsed->content,
            finishReason: $parsed->finishReason,
            toolCalls: $parsed->toolCalls,
            model: $parsed->model,
            usage: $parsed->usage,
            reasoning: $parsed->reasoning,
            contentBlocks: $this->rawContentBlocks($response),
        );
    }

    /**
     * The response's `content` blocks decoded from the raw body with objects
     * kept as stdClass, so `{}` and `[]` (and numeric-key objects) encode back
     * exactly. Only each top-level block becomes an array. decodeResponse()
     * has already read the body, refused a redirect and thrown on 4xx/5xx.
     *
     * @return list<array<string, mixed>>
     */
    private function rawContentBlocks(ResponseInterface $response): array
    {
        $raw = json_decode($response->getContent());
        $content = $raw instanceof \stdClass ? ($raw->content ?? null) : null;
        if (!is_array($content)) {
            return [];
        }

        $blocks = [];
        foreach ($content as $block) {
            if ($block instanceof \stdClass) {
                /** @var array<string, mixed> $fields */
                $fields = get_object_vars($block);
                $blocks[] = $fields;
            }
        }

        return $blocks;
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $payload = [...$this->buildPayload($messages, $tools, $options), 'stream' => true];

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        // Anthropic's stream, by the `type` field of each data payload:
        //   message_start        → model and input (prompt) token usage
        //   content_block_start  → opens the block at `index` (text, thinking, tool_use);
        //                          redacted_thinking arrives complete here, with `data`
        //   content_block_delta  → text_delta / thinking_delta are yielded as they arrive and
        //                          kept on the block; signature_delta is kept on the thinking
        //                          block; input_json_delta accumulates the tool's arguments
        //   content_block_stop   → block finalized
        //   message_delta        → may repeat; carries stop_reason and cumulative output_tokens
        //   message_stop         → end of message: exactly one terminal chunk is yielded
        //   error                → thrown as a ProviderException
        // A stream that ends before message_stop yields a terminal Error chunk.
        // The terminal chunk carries the rebuilt blocks (contentBlocks) so the
        // agent can send thinking and its signature back unchanged.
        /** @var array<int, array{type: string, id: string, name: string, json: string, text: string, thinking: string, signature: string, data: string}> $blocks */
        $blocks = [];
        $currentBlockIndex = -1;
        $model = $this->model;
        $promptTokens = null;
        $completionTokens = null;
        $stopReason = null;

        $parser = new SseStreamParser($this->httpClient, $response);

        foreach ($parser->events() as $json) {
            $eventType = $json['type'] ?? null;

            if ($eventType === 'content_block_start') {
                $currentBlockIndex = $json['index'] ?? 0;
                $block = $json['content_block'] ?? [];
                $blocks[$currentBlockIndex] = [
                    'type' => $block['type'] ?? 'text',
                    'id' => $block['id'] ?? '',
                    'name' => $block['name'] ?? '',
                    'json' => '',
                    'text' => '',
                    'thinking' => '',
                    'signature' => '',
                    'data' => $block['data'] ?? '',
                ];
            } elseif ($eventType === 'content_block_delta') {
                $delta = $json['delta'] ?? [];
                $index = $json['index'] ?? $currentBlockIndex;
                $deltaType = $delta['type'] ?? '';

                if ($deltaType === 'input_json_delta' && isset($blocks[$index])) {
                    $blocks[$index]['json'] .= $delta['partial_json'] ?? '';
                } elseif ($deltaType === 'signature_delta' && isset($blocks[$index])) {
                    $blocks[$index]['signature'] .= $delta['signature'] ?? '';
                } elseif ($deltaType === 'thinking_delta' && isset($delta['thinking'])) {
                    if (isset($blocks[$index])) {
                        $blocks[$index]['thinking'] .= $delta['thinking'];
                    }
                    yield new Response(
                        content: '',
                        finishReason: ProviderFinishReason::Stop,
                        toolCalls: [],
                        model: $model,
                        reasoning: $delta['thinking'],
                    );
                } elseif ($deltaType === 'text_delta' && isset($delta['text'])) {
                    if (isset($blocks[$index])) {
                        $blocks[$index]['text'] .= $delta['text'];
                    }
                    yield new Response(
                        content: $delta['text'],
                        finishReason: ProviderFinishReason::Stop,
                        toolCalls: [],
                        model: $model,
                    );
                }
            } elseif ($eventType === 'message_start') {
                $model = $json['message']['model'] ?? $model;
                $promptTokens = $json['message']['usage']['input_tokens'] ?? $promptTokens;
            } elseif ($eventType === 'message_delta') {
                $stopReason = $json['delta']['stop_reason'] ?? $stopReason;
                $completionTokens = $json['usage']['output_tokens'] ?? $completionTokens;
            } elseif ($eventType === 'message_stop') {
                yield $this->streamTerminal($stopReason, $blocks, $model, $promptTokens, $completionTokens);

                return;
            } elseif ($eventType === 'error') {
                throw ProviderException::streamError(
                    (string) ($json['error']['type'] ?? 'error'),
                    (string) ($json['error']['message'] ?? 'unknown stream error'),
                );
            }
        }

        // EOF before message_stop: the message is incomplete, whatever arrived.
        yield $this->streamTerminal(null, [], $model, $promptTokens, $completionTokens);
    }

    /**
     * The single chunk that ends a stream. No stop reason means the message
     * did not finish (Error). Tool calls are emitted only for ToolUse, and only
     * when every tool's arguments decode to a JSON object; otherwise Error.
     * Content blocks are kept only on a Stop or ToolUse end.
     *
     * @param array<int, array{type: string, id: string, name: string, json: string, text: string, thinking: string, signature: string, data: string}> $blocks
     */
    private function streamTerminal(?string $stopReason, array $blocks, string $model, ?int $promptTokens, ?int $completionTokens): Response
    {
        $finishReason = $stopReason === null ? ProviderFinishReason::Error : $this->mapStopReason($stopReason);
        $toolCalls = [];

        if ($finishReason === ProviderFinishReason::ToolUse) {
            foreach ($blocks as $block) {
                if ($block['type'] !== 'tool_use') {
                    continue;
                }

                $arguments = $this->decodeToolArguments($block['json']);
                if ($arguments === null) {
                    $this->logger?->debug('Anthropic tool {tool} arguments ({length} bytes) are not a JSON object; ending the stream with Error', [
                        'tool' => $block['name'],
                        'length' => strlen($block['json']),
                    ]);
                    $finishReason = ProviderFinishReason::Error;
                    $toolCalls = [];
                    break;
                }

                $toolCalls[] = new ToolCall(id: $block['id'], name: $block['name'], arguments: $arguments);
            }
        }

        $usage = null;
        if ($promptTokens !== null || $completionTokens !== null) {
            $usage = new Usage(
                promptTokens: $promptTokens ?? 0,
                completionTokens: $completionTokens ?? 0,
                totalTokens: ($promptTokens ?? 0) + ($completionTokens ?? 0),
            );
        }

        return new Response(
            content: '',
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $model,
            usage: $usage,
            contentBlocks: $finishReason === ProviderFinishReason::Stop || $finishReason === ProviderFinishReason::ToolUse
                ? $this->rebuildContentBlocks($blocks)
                : [],
        );
    }

    /**
     * Rebuild the streamed blocks in index order, in the shape the Messages API
     * returns them. Any block type other than thinking, redacted_thinking, text
     * and tool_use, or tool JSON that is not an object, gives [] so that replay
     * falls back to re-formatting the message without thinking.
     *
     * @param array<int, array{type: string, id: string, name: string, json: string, text: string, thinking: string, signature: string, data: string}> $blocks
     * @return list<array<string, mixed>>
     */
    private function rebuildContentBlocks(array $blocks): array
    {
        ksort($blocks);
        $rebuilt = [];

        foreach ($blocks as $block) {
            $rebuiltBlock = match ($block['type']) {
                'thinking' => ['type' => 'thinking', 'thinking' => $block['thinking'], 'signature' => $block['signature']],
                'redacted_thinking' => ['type' => 'redacted_thinking', 'data' => $block['data']],
                'text' => ['type' => 'text', 'text' => $block['text']],
                'tool_use' => $this->rebuildToolUse($block),
                default => null,
            };

            if ($rebuiltBlock === null) {
                return [];
            }

            $rebuilt[] = $rebuiltBlock;
        }

        return $rebuilt;
    }

    /**
     * @param array{id: string, name: string, json: string} $block
     * @return array<string, mixed>|null null when the arguments are not a JSON object
     */
    private function rebuildToolUse(array $block): ?array
    {
        $input = $block['json'] === '' ? new \stdClass() : json_decode($block['json']);

        return $input instanceof \stdClass
            ? ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => $input]
            : null;
    }

    /**
     * Decode a tool's accumulated `partial_json`. An empty string is `{}`;
     * anything that is not a JSON object (truncated, array, scalar) is null.
     *
     * @return array<string, mixed>|null
     */
    private function decodeToolArguments(string $json): ?array
    {
        if ($json === '') {
            return [];
        }

        if (!json_decode($json) instanceof \stdClass) {
            return null;
        }

        /** @var array<string, mixed> */
        return json_decode($json, true);
    }

    private function mapStopReason(?string $stopReason): ProviderFinishReason
    {
        return match ($stopReason) {
            'end_turn', 'stop_sequence' => ProviderFinishReason::Stop,
            'tool_use' => ProviderFinishReason::ToolUse,
            'max_tokens' => ProviderFinishReason::MaxTokens,
            'refusal' => ProviderFinishReason::Refusal,
            'pause_turn' => ProviderFinishReason::PauseTurn,
            'model_context_window_exceeded' => ProviderFinishReason::ContextWindowExceeded,
            default => $this->unknownStopReason($stopReason),
        };
    }

    private function unknownStopReason(?string $stopReason): ProviderFinishReason
    {
        if ($stopReason !== null) {
            $this->logger?->debug('Unknown Anthropic stop_reason {stop_reason}; treating it as stop', ['stop_reason' => $stopReason]);
        }

        return ProviderFinishReason::Stop;
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

        $payload = $this->buildPayload($messages, [], $options);
        $payload['tools'] = [
            [
                'name' => $toolName,
                'description' => $description,
                'input_schema' => $inputSchema,
            ],
        ];
        $payload['tool_choice'] = ['type' => 'tool', 'name' => $toolName];

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        $data = $this->decodeResponse($response);

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
     * Build the Messages API payload. Caller options override every field
     * except the conversation (`messages`) and the transport flag (`stream`),
     * which the provider owns; stream() sets `stream` itself.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $tools, array $options): array
    {
        [$systemPrompt, $formattedMessages] = $this->extractSystemAndMessages($messages);

        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        unset($options['stream']);
        return [...$payload, ...$options, 'messages' => $formattedMessages];
    }

    public function models(): array
    {
        // Attempt to fetch from Anthropic's models API (available since 2024)
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", [...$this->requestLimits->apply([
                'headers' => $this->headers(),
            ]), 'timeout' => 10.0]);

            $data = $this->decodeResponse($response);
            $models = [];

            foreach ($data['data'] ?? [] as $model) {
                $models[] = new ModelDefinition(
                    id: $model['id'] ?? '',
                    name: $model['display_name'] ?? $model['id'] ?? '',
                    provider: 'anthropic',
                    contextWindow: $model['context_window'] ?? 200000,
                    maxTokens: $model['max_output'] ?? 4096,
                    family: 'claude',
                    toolCalls: true,
                    metadataSource: 'provider-api',
                    fieldSources: [
                        'contextWindow' => 'provider-api',
                        'maxTokens' => 'provider-api',
                    ],
                );
            }

            if (!empty($models)) {
                return $models;
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch Anthropic models: {error}', ['error' => $e->getMessage()]);
            // Fall through to static list
        }

        // Static fallback — kept up-to-date with major releases
        return [
            new ModelDefinition(id: 'claude-opus-4-20250514', name: 'Claude Opus 4', provider: 'anthropic', contextWindow: 200000, maxTokens: 32000, family: 'claude', toolCalls: true, metadataSource: 'static-fallback', fieldSources: ['contextWindow' => 'static-fallback', 'maxTokens' => 'static-fallback']),
            new ModelDefinition(id: 'claude-sonnet-4-20250514', name: 'Claude Sonnet 4', provider: 'anthropic', contextWindow: 200000, maxTokens: 16000, family: 'claude', toolCalls: true, metadataSource: 'static-fallback', fieldSources: ['contextWindow' => 'static-fallback', 'maxTokens' => 'static-fallback']),
            new ModelDefinition(id: 'claude-3-5-sonnet-20241022', name: 'Claude 3.5 Sonnet', provider: 'anthropic', contextWindow: 200000, maxTokens: 8192, family: 'claude', toolCalls: true, metadataSource: 'static-fallback', fieldSources: ['contextWindow' => 'static-fallback', 'maxTokens' => 'static-fallback']),
            new ModelDefinition(id: 'claude-3-5-haiku-20241022', name: 'Claude 3.5 Haiku', provider: 'anthropic', contextWindow: 200000, maxTokens: 8192, family: 'claude', toolCalls: true, metadataSource: 'static-fallback', fieldSources: ['contextWindow' => 'static-fallback', 'maxTokens' => 'static-fallback']),
            new ModelDefinition(id: 'claude-3-opus-20240229', name: 'Claude 3 Opus', provider: 'anthropic', contextWindow: 200000, maxTokens: 4096, family: 'claude', toolCalls: true, metadataSource: 'static-fallback', fieldSources: ['contextWindow' => 'static-fallback', 'maxTokens' => 'static-fallback']),
            new ModelDefinition(id: 'claude-3-haiku-20240307', name: 'Claude 3 Haiku', provider: 'anthropic', contextWindow: 200000, maxTokens: 4096, family: 'claude', toolCalls: true, metadataSource: 'static-fallback', fieldSources: ['contextWindow' => 'static-fallback', 'maxTokens' => 'static-fallback']),
        ];
    }

    public function isAvailable(): bool
    {
        if ($this->apiKey === '') {
            return false;
        }

        try {
            $this->httpClient->request('GET', "{$this->baseUrl}/models", [...$this->requestLimits->apply([
                'headers' => $this->headers(),
            ]), 'timeout' => 5.0]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->debug('Anthropic availability check failed: {error}', ['error' => $e->getMessage()]);

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

            // An empty assistant turn (the agent's nudge and refusal paths add
            // them) is rejected by Anthropic; dropping it lets the user turns
            // around it merge below.
            if ($this->isEmptyAssistantTurn($message)) {
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
                $merged[$lastKey]['content'] = [...$lastContent, ...$msgContent];
            } else {
                $merged[] = $msg;
            }
        }

        return [$systemPrompt, $merged];
    }

    private function isEmptyAssistantTurn(MessageInterface $message): bool
    {
        return $message->role() === Role::Assistant
            && in_array($message->content(), ['', []], true)
            && $message->toolCalls() === []
            && (!$message instanceof AssistantMessage || $message->contentBlocks() === []);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAnthropicMessage(MessageInterface $message): array
    {
        // Blocks from this run (thinking with its signature) go back unchanged.
        if ($message instanceof AssistantMessage && $message->contentBlocks() !== []) {
            return ['role' => 'assistant', 'content' => $message->contentBlocks()];
        }

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
        $reasoning = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'] ?? '';
            } elseif ($block['type'] === 'thinking') {
                $reasoning .= $block['thinking'] ?? '';
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'] ?? '',
                    name: $block['name'] ?? '',
                    arguments: $block['input'] ?? [],
                );
            }
        }

        $finishReason = $this->mapStopReason($data['stop_reason'] ?? null);

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
            reasoning: $reasoning,
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
