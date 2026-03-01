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

/**
 * Provider for OpenAI's Responses API (/v1/responses).
 *
 * Required for Codex models (gpt-5-codex, etc.) that do not support
 * the Chat Completions endpoint. Also works with standard models
 * like gpt-4o and gpt-5 as a forward-compatible replacement.
 *
 * Key differences from Chat Completions:
 * - Endpoint: POST /v1/responses (not /v1/chat/completions)
 * - Request uses `input` array (not `messages`)
 * - Tool results are `function_call_output` items (not role=tool messages)
 * - Response uses `output` array of items (not choices[0].message)
 * - Streaming uses different event types (response.output_text.delta, etc.)
 */
final class OpenAIResponsesProvider extends AbstractProvider
{
    public function __construct(
        string $model,
        string $baseUrl = 'https://api.openai.com/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($model, $baseUrl, $apiKey, $httpClient);
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $payload = [
            'model' => $this->model,
            'input' => $this->formatMessages($messages),
            ...$options,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/responses", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        return $this->parseResponse($response->toArray());
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $payload = [
            'model' => $this->model,
            'input' => $this->formatMessages($messages),
            'stream' => true,
            ...$options,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/responses", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        /** @var array<int, array{call_id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];

        // Buffer incomplete SSE lines across HTTP chunks. Responses API
        // payloads (especially tool call arguments) can be large enough
        // that a single `data:` line spans multiple HTTP chunks.
        $lineBuffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $data = $lineBuffer . $chunk->getContent();
            $lineBuffer = '';

            $lines = explode("\n", $data);

            // If the data doesn't end with a newline, the last element
            // is an incomplete line — buffer it for the next chunk.
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

                if ($eventType === 'response.output_text.delta') {
                    $delta = $json['delta'] ?? '';
                    if ($delta !== '') {
                        yield new Response(
                            content: $delta,
                            finishReason: FinishReason::Stop,
                            toolCalls: [],
                            model: $this->model,
                        );
                    }
                } elseif ($eventType === 'response.output_item.added') {
                    $item = $json['item'] ?? [];
                    if (($item['type'] ?? '') === 'function_call') {
                        $index = $json['output_index'] ?? 0;
                        $pendingToolCalls[$index] = [
                            'call_id' => $item['call_id'] ?? '',
                            'name' => $item['name'] ?? '',
                            'arguments' => '',
                        ];
                    }
                } elseif ($eventType === 'response.function_call_arguments.delta') {
                    $index = $json['output_index'] ?? 0;
                    if (isset($pendingToolCalls[$index])) {
                        $pendingToolCalls[$index]['arguments'] .= $json['delta'] ?? '';
                    }
                } elseif ($eventType === 'response.output_item.done') {
                    $item = $json['item'] ?? [];
                    if (($item['type'] ?? '') === 'function_call') {
                        $index = $json['output_index'] ?? 0;
                        $pendingToolCalls[$index] = [
                            'call_id' => $item['call_id'] ?? $pendingToolCalls[$index]['call_id'] ?? '',
                            'name' => $item['name'] ?? $pendingToolCalls[$index]['name'] ?? '',
                            'arguments' => $item['arguments'] ?? $pendingToolCalls[$index]['arguments'] ?? '',
                        ];
                    }
                } elseif ($eventType === 'response.completed') {
                    $responseData = $json['response'] ?? [];
                    $usage = $this->parseUsage($responseData);

                    if (!empty($pendingToolCalls)) {
                        $toolCalls = [];
                        foreach ($pendingToolCalls as $tc) {
                            $toolCalls[] = new ToolCall(
                                id: $tc['call_id'],
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
                    } else {
                        yield new Response(
                            content: '',
                            finishReason: FinishReason::Stop,
                            toolCalls: [],
                            model: $this->model,
                            usage: $usage,
                        );
                    }
                } elseif ($eventType === 'response.created') {
                    // Initial response metadata — extract model if present
                    $responseData = $json['response'] ?? [];
                    $reportedModel = $responseData['model'] ?? '';

                    if (isset($responseData['usage'])) {
                        yield new Response(
                            content: '',
                            finishReason: FinishReason::Stop,
                            toolCalls: [],
                            model: $reportedModel !== '' ? $reportedModel : $this->model,
                            usage: $this->parseUsage($responseData),
                        );
                    }
                }
            }
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $schemaData = json_decode($schema, true);
        if ($schemaData === null) {
            return $this->chat($messages, [], $options);
        }

        $toolName = $schemaData['name'] ?? 'structured_output';
        $description = $schemaData['description'] ?? 'Generate structured output matching the schema.';
        $inputSchema = $schemaData['schema'] ?? $schemaData['parameters'] ?? $schemaData;

        if (!isset($inputSchema['type'])) {
            $inputSchema['type'] = 'object';
        }

        $payload = [
            'model' => $this->model,
            'input' => $this->formatMessages($messages),
            'tools' => [
                [
                    'type' => 'function',
                    'name' => $toolName,
                    'description' => $description,
                    'parameters' => $inputSchema,
                ],
            ],
            'tool_choice' => ['type' => 'function', 'name' => $toolName],
            ...$options,
        ];

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/responses", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        $data = $response->toArray();

        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'function_call' && ($item['name'] ?? '') === $toolName) {
                return json_decode($item['arguments'] ?? '{}', true) ?? [];
            }
        }

        return $this->parseResponse($data);
    }

    public function models(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", [
                'headers' => $this->headers(),
            ]);

            $data = $response->toArray();
            $models = [];

            foreach ($data['data'] ?? [] as $model) {
                $models[] = new ModelDefinition(
                    id: $model['id'] ?? '',
                    name: $model['id'] ?? '',
                    provider: 'openai',
                );
            }

            return $models;
        } catch (\Throwable) {
            return [];
        }
    }

    public function isAvailable(): bool
    {
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
        return array_map(function (ToolInterface $tool): array {
            $schema = $tool->toFunctionSchema();
            $parameters = $schema['function']['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];

            // Strict mode requires every key in properties to be listed in
            // required, with optional properties typed as nullable (anyOf null).
            $parameters = $this->normalizeSchemaForStrictMode($parameters);

            return [
                'type' => 'function',
                'name' => $schema['function']['name'],
                'description' => $schema['function']['description'],
                'parameters' => $parameters,
                'strict' => true,
            ];
        }, $tools);
    }

    /**
     * Normalize a JSON Schema object for OpenAI strict mode.
     *
     * Strict mode rules:
     * - `required` must list every key present in `properties`.
     * - Optional properties (those not originally required) must be typed
     *   as nullable via `anyOf: [{...original}, {type: "null"}]`.
     * - `additionalProperties` must be `false`.
     *
     * Applied recursively to nested object schemas.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeSchemaForStrictMode(array $schema): array
    {
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            $schema['additionalProperties'] = false;

            return $schema;
        }

        $allKeys = array_keys($schema['properties']);
        $required = isset($schema['required']) && is_array($schema['required'])
            ? $schema['required']
            : [];
        $optionalKeys = array_diff($allKeys, $required);

        // Wrap optional properties as nullable so the schema stays valid
        foreach ($optionalKeys as $key) {
            $prop = $schema['properties'][$key];
            if (!isset($prop['anyOf'])) {
                $schema['properties'][$key] = ['anyOf' => [$prop, ['type' => 'null']]];
            }
        }

        // Recurse into nested object properties
        foreach ($schema['properties'] as $key => $prop) {
            if (is_array($prop) && ($prop['type'] ?? '') === 'object') {
                $schema['properties'][$key] = $this->normalizeSchemaForStrictMode($prop);
            }
        }

        $schema['required'] = $allKeys;
        $schema['additionalProperties'] = false;

        return $schema;
    }

    protected function formatMessages(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            match ($message->role()) {
                Role::System => $input[] = [
                    'role' => 'system',
                    'content' => $this->stringifyContent($message->content()),
                ],
                Role::User => $input[] = [
                    'role' => 'user',
                    'content' => $message->content(),
                ],
                Role::Assistant => $this->formatAssistantMessage($message, $input),
                Role::Tool => $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $message->toolCallId() ?? '',
                    'output' => $this->stringifyContent($message->content()),
                ],
            };
        }

        return $input;
    }

    protected function parseResponse(array $data): Response
    {
        $content = '';
        $toolCalls = [];

        foreach ($data['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'output_text') {
                        $content .= $block['text'] ?? '';
                    }
                }
            } elseif ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    id: $item['call_id'] ?? '',
                    name: $item['name'] ?? '',
                    arguments: json_decode($item['arguments'] ?? '{}', true) ?? [],
                );
            }
        }

        $finishReason = match ($data['status'] ?? 'completed') {
            'completed' => empty($toolCalls) ? FinishReason::Stop : FinishReason::ToolUse,
            'incomplete' => FinishReason::MaxTokens,
            default => FinishReason::Stop,
        };

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $this->parseUsage($data),
        );
    }

    /**
     * Format an assistant message for the Responses API input array.
     *
     * Text content becomes a standard assistant message. Tool calls
     * become separate function_call items with their own call_id.
     *
     * @param array<array<string, mixed>> &$input
     */
    private function formatAssistantMessage(MessageInterface $message, array &$input): void
    {
        $content = $message->content();
        if (is_string($content) && $content !== '') {
            $input[] = [
                'role' => 'assistant',
                'content' => $content,
            ];
        }

        foreach ($message->toolCalls() as $toolCall) {
            $input[] = [
                'type' => 'function_call',
                'call_id' => $toolCall->id,
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: new \stdClass()),
            ];
        }
    }

    /**
     * Parse usage data from a Responses API response.
     *
     * The Responses API uses `input_tokens` and `output_tokens`
     * rather than Chat Completions' `prompt_tokens` and `completion_tokens`.
     *
     * @param array<string, mixed> $data
     */
    private function parseUsage(array $data): ?Usage
    {
        if (!isset($data['usage'])) {
            return null;
        }

        $usage = $data['usage'];

        return new Usage(
            promptTokens: $usage['input_tokens'] ?? 0,
            completionTokens: $usage['output_tokens'] ?? 0,
            totalTokens: ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
        );
    }

    /**
     * Ensure content is a string for contexts that require it.
     *
     * @param string|array<mixed> $content
     */
    private function stringifyContent(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return json_encode($content) ?: '';
    }
}
