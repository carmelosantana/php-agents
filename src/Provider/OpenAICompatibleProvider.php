<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAICompatibleProvider extends AbstractProvider
{
    public function __construct(
        string $model,
        string $baseUrl = 'http://localhost:11434/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        protected readonly string $discoveredProviderName = 'openai',
    ) {
        parent::__construct($model, $baseUrl, $apiKey, $httpClient, $logger);
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $tools = $this->trimToolsToLimit($tools, self::OPENAI_MAX_TOOLS, 'openai-compatible', '/chat/completions');

        // chat() is never a streaming call — stream_options is a streaming-only
        // OpenAI extension. Remove it if a caller (e.g. OllamaProvider) sets it
        // to null to suppress it from the stream() path as well.
        unset($options['stream_options']);

        $payload = [
            'model' => $this->model,
            'messages' => $this->formatMessages($messages),
            'stream' => false,
            ...$options,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/chat/completions", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        return $this->parseResponse($response->toArray());
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $tools = $this->trimToolsToLimit($tools, self::OPENAI_MAX_TOOLS, 'openai-compatible', '/chat/completions');

        // Allow callers to suppress stream_options by passing null (e.g. OllamaProvider).
        // Default to include_usage so OpenAI-compatible providers return token counts.
        $streamOptions = array_key_exists('stream_options', $options)
            ? $options['stream_options']
            : ['include_usage' => true];
        unset($options['stream_options']);

        $payload = [
            'model' => $this->model,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
            ...$options,
        ];

        if ($streamOptions !== null) {
            $payload['stream_options'] = $streamOptions;
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/chat/completions", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        // Accumulate tool call deltas across chunks. OpenAI streams tool calls
        // as incremental fragments: the first chunk carries the tool ID and
        // function name, subsequent chunks carry argument JSON fragments.
        /** @var array<int, array{id: string, name: string, arguments: string}> $pendingToolCalls */
        $pendingToolCalls = [];

        $parser = new SseStreamParser($this->httpClient, $response);

        foreach ($parser->events() as $json) {
            $choice = $json['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];
            $finishReason = $choice['finish_reason'] ?? null;

            // Accumulate tool call deltas
            foreach ($delta['tool_calls'] ?? [] as $tc) {
                $index = $tc['index'] ?? 0;

                if (isset($tc['id'])) {
                    // First chunk for this tool call — initialize
                    $pendingToolCalls[$index] = [
                        'id' => $tc['id'],
                        'name' => $tc['function']['name'] ?? '',
                        'arguments' => $tc['function']['arguments'] ?? '',
                    ];
                } elseif (isset($pendingToolCalls[$index])) {
                    // Subsequent chunk — append argument fragment
                    $pendingToolCalls[$index]['arguments'] .= $tc['function']['arguments'] ?? '';
                }
            }

            // Reasoning content from thinking models (qwen3.5, DeepSeek-R1).
            // Models use different field names: reasoning (Ollama/qwen),
            // reasoning_content (DeepSeek), thinking (some others). Each delta
            // is yielded as-is; accumulation is the consumer's responsibility
            // so reasoning is never double-emitted.
            $reasoningDelta = $delta['reasoning']
                ?? $delta['reasoning_content']
                ?? $delta['thinking']
                ?? null;
            if ($reasoningDelta !== null && $reasoningDelta !== '') {
                yield new Response(
                    content: '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $json['model'] ?? $this->model,
                    reasoning: $reasoningDelta,
                );
            }

            // When the stream signals tool_calls finish, yield a
            // Response with the fully-assembled ToolCall objects.
            if ($finishReason === 'tool_calls') {
                if (!empty($pendingToolCalls)) {
                    $toolCalls = [];
                    foreach ($pendingToolCalls as $tc) {
                        $toolCalls[] = new ToolCall(
                            id: $tc['id'],
                            name: $tc['name'],
                            arguments: json_decode($tc['arguments'], true) ?? [],
                        );
                    }

                    yield new Response(
                        content: $delta['content'] ?? '',
                        finishReason: ProviderFinishReason::ToolUse,
                        toolCalls: $toolCalls,
                        model: $json['model'] ?? $this->model,
                    );

                    $pendingToolCalls = [];
                    continue;
                }

                // Ollama thinking+tools bug (ollama/ollama#10976): finish_reason
                // arrives as tool_calls but no tool-call deltas were streamed.
                // Yield a Stop so the consumer applies empty-response handling
                // instead of the chunk being dropped silently.
                $this->logger?->debug('tool_calls finish with no pending tool calls — treating as stop');

                yield new Response(
                    content: $delta['content'] ?? '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $json['model'] ?? $this->model,
                );
                continue;
            }

            // Usage-only chunk (sent after all content when
            // stream_options.include_usage is true). The choices
            // array is empty but usage data is present.
            if (empty($json['choices']) && isset($json['usage'])) {
                yield new Response(
                    content: '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $json['model'] ?? $this->model,
                    usage: new Usage(
                        promptTokens: $json['usage']['prompt_tokens'] ?? 0,
                        completionTokens: $json['usage']['completion_tokens'] ?? 0,
                        totalTokens: $json['usage']['total_tokens'] ?? 0,
                    ),
                );
                continue;
            }

            // Regular text delta — yield immediately
            if (isset($delta['content']) && $delta['content'] !== '') {
                yield new Response(
                    content: $delta['content'],
                    finishReason: $this->mapFinishReason($finishReason),
                    toolCalls: [],
                    model: $json['model'] ?? $this->model,
                );
            } elseif ($finishReason === 'stop') {
                // Final content chunk (stop signal, usage may follow
                // in a separate chunk when stream_options is set)
                $usage = null;
                if (isset($json['usage'])) {
                    $usage = new Usage(
                        promptTokens: $json['usage']['prompt_tokens'] ?? 0,
                        completionTokens: $json['usage']['completion_tokens'] ?? 0,
                        totalTokens: $json['usage']['total_tokens'] ?? 0,
                    );
                }

                yield new Response(
                    content: '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $json['model'] ?? $this->model,
                    usage: $usage,
                );
            }
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $schemaData = json_decode($schema, true);

        return $this->chat($messages, [], [
            ...$options,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $schemaData,
            ],
        ]);
    }

    public function models(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", [
                'headers' => $this->headers(),
            ]);

            $data = $response->toArray();
            $models = [];

                foreach ($this->extractModelEntries($data) as $model) {
                    $definition = $this->buildDiscoveredModelDefinition($model);
                    if ($definition !== null) {
                        $models[] = $definition;
                    }
            }

            return $models;
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch models: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    protected function extractModelEntries(array $data): array
    {
        $entries = $data['data'] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $model
     */
    protected function buildDiscoveredModelDefinition(array $model): ?ModelDefinition
    {
        return ModelDefinition::fromDiscovery($this->discoveredProviderName, $model);
    }

    public function isAvailable(): bool
    {
        try {
            $this->httpClient->request('GET', "{$this->baseUrl}/models", [
                'headers' => $this->headers(),
                'timeout' => 5,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->debug('Provider availability check failed: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }

    protected function formatTools(array $tools): array
    {
        return array_map(function (ToolInterface $tool) {
            $schema = $tool->toFunctionSchema();

            // OpenAI requires 'required' to always be present, even for zero-parameter tools
            if (!isset($schema['function']['parameters']['required'])) {
                $schema['function']['parameters']['required'] = [];
            }

            return $schema;
        }, $tools);
    }

    protected function formatMessages(array $messages): array
    {
        return array_map(fn(MessageInterface $msg) => $msg->toArray(), $messages);
    }

    protected function parseResponse(array $data): Response
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $finishReason = $this->mapFinishReason($choice['finish_reason'] ?? 'stop');

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $arguments = $tc['function']['arguments'] ?? '{}';
            $toolCalls[] = new ToolCall(
                id: $tc['id'] ?? '',
                name: $tc['function']['name'] ?? '',
                arguments: json_decode($arguments, true) ?? [],
            );
        }

        $usage = null;
        if (isset($data['usage'])) {
            $usage = new Usage(
                promptTokens: $data['usage']['prompt_tokens'] ?? 0,
                completionTokens: $data['usage']['completion_tokens'] ?? 0,
                totalTokens: $data['usage']['total_tokens'] ?? 0,
            );
        }

        // Ollama non-streaming thinking models return a top-level `thinking` field
        // on the message object alongside `content`.
        $reasoning = $message['thinking'] ?? '';

        return new Response(
            content: $message['content'] ?? '',
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $usage,
            reasoning: is_string($reasoning) ? $reasoning : '',
        );
    }

    protected function mapFinishReason(?string $reason): ProviderFinishReason
    {
        return match ($reason) {
            'stop' => ProviderFinishReason::Stop,
            'tool_calls', 'function_call' => ProviderFinishReason::ToolUse,
            'length' => ProviderFinishReason::MaxTokens,
            default => ProviderFinishReason::Stop,
        };
    }
}
