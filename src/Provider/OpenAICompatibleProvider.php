<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAICompatibleProvider extends AbstractProvider
{
    public function __construct(
        string $model,
        string $baseUrl = 'http://localhost:11434/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($model, $baseUrl, $apiKey, $httpClient);
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
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
        $payload = [
            'model' => $this->model,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            ...$options,
        ];

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

        // Buffer incomplete SSE lines across HTTP chunks. Large payloads
        // (tool call arguments, usage data) can span chunk boundaries.
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
                if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                    $json = json_decode(substr($line, 6), true);
                    if ($json === null) {
                        continue;
                    }

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

                    // When the stream signals tool_calls finish, yield a
                    // Response with the fully-assembled ToolCall objects.
                    if ($finishReason === 'tool_calls' && !empty($pendingToolCalls)) {
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
                            finishReason: FinishReason::ToolUse,
                            toolCalls: $toolCalls,
                            model: $json['model'] ?? $this->model,
                        );

                        $pendingToolCalls = [];
                        continue;
                    }

                    // Usage-only chunk (sent after all content when
                    // stream_options.include_usage is true). The choices
                    // array is empty but usage data is present.
                    if (empty($json['choices']) && isset($json['usage'])) {
                        yield new Response(
                            content: '',
                            finishReason: FinishReason::Stop,
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
                            finishReason: FinishReason::Stop,
                            toolCalls: [],
                            model: $json['model'] ?? $this->model,
                            usage: $usage,
                        );
                    }
                }
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
        return array_map(fn(ToolInterface $tool) => $tool->toFunctionSchema(), $tools);
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

        return new Response(
            content: $message['content'] ?? '',
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $usage,
        );
    }

    protected function mapFinishReason(?string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'tool_calls', 'function_call' => FinishReason::ToolUse,
            'length' => FinishReason::MaxTokens,
            default => FinishReason::Stop,
        };
    }
}
