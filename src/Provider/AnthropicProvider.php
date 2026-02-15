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

        foreach ($this->httpClient->stream($response) as $chunk) {
            $data = $chunk->getContent();
            foreach (explode("\n", $data) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $json = json_decode(substr($line, 6), true);
                    if ($json !== null && isset($json['type'])) {
                        yield $this->parseStreamEvent($json);
                    }
                }
            }
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        return $this->chat($messages, [], $options);
    }

    public function models(): array
    {
        return [
            new ModelDefinition(id: 'claude-sonnet-4-20250514', name: 'Claude Sonnet 4', provider: 'anthropic'),
            new ModelDefinition(id: 'claude-opus-4-20250514', name: 'Claude Opus 4', provider: 'anthropic'),
            new ModelDefinition(id: 'claude-3-5-sonnet-20241022', name: 'Claude 3.5 Sonnet', provider: 'anthropic'),
        ];
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
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
        // user message with multiple content blocks.
        $merged = [];
        foreach ($formatted as $msg) {
            $last = end($merged);
            if ($last !== false && $last['role'] === $msg['role'] && is_array($last['content']) && is_array($msg['content'])) {
                $merged[array_key_last($merged)]['content'] = array_merge($last['content'], $msg['content']);
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
            'content' => $message->content(),
        ];
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
     * @param array<string, mixed> $event
     */
    private function parseStreamEvent(array $event): Response
    {
        $content = '';

        if ($event['type'] === 'content_block_delta') {
            $delta = $event['delta'] ?? [];
            if (isset($delta['text'])) {
                $content = $delta['text'];
            }
        }

        return new Response(
            content: $content,
            finishReason: FinishReason::Stop,
            toolCalls: [],
            model: $this->model,
        );
    }
}
