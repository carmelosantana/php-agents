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
            ...$options,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/chat/completions", [
            'headers' => $this->headers(),
            'json' => $payload,
        ]);

        foreach ($this->httpClient->stream($response) as $chunk) {
            $data = $chunk->getContent();
            foreach (explode("\n", $data) as $line) {
                if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                    $json = json_decode(substr($line, 6), true);
                    if ($json !== null) {
                        yield $this->parseStreamChunk($json);
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

    /**
     * @param array<string, mixed> $data
     */
    protected function parseStreamChunk(array $data): Response
    {
        $choice = $data['choices'][0] ?? [];
        $delta = $choice['delta'] ?? [];

        return new Response(
            content: $delta['content'] ?? '',
            finishReason: $this->mapFinishReason($choice['finish_reason'] ?? null),
            toolCalls: [],
            model: $data['model'] ?? $this->model,
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
