<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeToolDefinition;

final readonly class LlamaCppHistoryFormatter
{
    public function __construct(
        private LlamaCppTemplateResolver $templateResolver,
        private LlamaCppToolPromptInjector $toolPromptInjector,
    ) {}

    /**
     * @param MessageInterface[] $messages
     * @param RuntimeToolDefinition[] $tools
     * @param array<string, mixed> $options
     */
    public function format(array $messages, RuntimeModelMetadata $metadata, array $tools = [], array $options = []): LlamaCppPromptContext
    {
        $template = $this->templateResolver->resolveTemplate($metadata, $options);
        $toolParser = null;

        if ($tools !== []) {
            if (!$metadata->supportsTools) {
                throw new \InvalidArgumentException("Model {$metadata->id} does not support tools.");
            }

            $toolParser = $this->templateResolver->resolveToolParser($metadata, $template, $options);
            if ($toolParser === null) {
                throw new \RuntimeException(
                    "Model {$metadata->id} has no configured llama.cpp tool parser for template {$template}.",
                );
            }
        }

        $blocks = [];
        foreach ($messages as $message) {
            $blocks[] = $this->renderMessage($template, $message);

            if ($message->toolCalls() !== []) {
                $blocks[] = $this->renderAssistantToolCalls($template, $message);
            }

            if ($message->toolCallId() !== null) {
                $blocks[] = $this->renderToolCallId($template, $message->toolCallId());
            }
        }

        $toolInstructions = $toolParser !== null
            ? $this->toolPromptInjector->inject($tools, $toolParser, $template)
            : null;

        if ($toolInstructions !== null && $toolInstructions !== '') {
            $blocks[] = $toolInstructions;
        }

        $blocks[] = $this->assistantCue($template);

        return new LlamaCppPromptContext(
            prompt: implode("\n\n", array_values(array_filter($blocks, static fn(string $block): bool => $block !== ''))),
            template: $template,
            toolParser: $toolParser,
        );
    }

    private function renderMessage(string $template, MessageInterface $message): string
    {
        $content = $this->renderMessageContent($message);

        return match ($template) {
            'chatml' => '<|' . $message->role()->value . '|>' . "\n" . $content,
            default => strtoupper($message->role()->value) . ': ' . $content,
        };
    }

    private function renderAssistantToolCalls(string $template, MessageInterface $message): string
    {
        $payload = json_encode(array_map(
            static fn($toolCall): array => [
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'arguments' => $toolCall->arguments,
            ],
            $message->toolCalls(),
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return match ($template) {
            'chatml' => '<|assistant_tool_calls|>' . "\n" . $payload,
            default => 'ASSISTANT_TOOL_CALLS: ' . $payload,
        };
    }

    private function renderToolCallId(string $template, string $toolCallId): string
    {
        return match ($template) {
            'chatml' => '<|tool_call_id|>' . "\n" . $toolCallId,
            default => 'TOOL_CALL_ID: ' . $toolCallId,
        };
    }

    private function assistantCue(string $template): string
    {
        return match ($template) {
            'chatml' => '<|assistant|>',
            default => 'ASSISTANT:',
        };
    }

    private function renderMessageContent(MessageInterface $message): string
    {
        $content = $message->content();
        if (is_string($content)) {
            return $content;
        }

        $parts = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
                continue;
            }

            if ($type === 'image_url') {
                $parts[] = '[image]';
            }
        }

        return trim(implode("\n", array_filter($parts, static fn(string $part): bool => $part !== '')));
    }
}