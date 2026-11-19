<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * Implements streamEvents() by converting the class's stream() chunks into typed events.
 *
 * Each chunk yields its reasoning, then its text, then one ToolInputStart, ToolInputDelta
 * (the full JSON-encoded arguments) and ToolInputEnd per tool call it carries. One Finish
 * follows the last chunk, merged across all chunks: the first failure reason (any reason
 * other than ToolUse) is sticky, otherwise the last reason that is not Stop wins; with no
 * such reason it is ToolUse when any call arrived, else Stop. Usage is max-merged per field,
 * and the last non-empty model and content blocks are kept.
 */
trait StreamsEventsFromResponses
{
    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<Response>
     */
    abstract public function stream(array $messages, array $tools = [], array $options = []): iterable;

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<StreamEvent>
     */
    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $reason = null;
        $usage = null;
        $model = '';
        $contentBlocks = [];
        $callCount = 0;

        foreach ($this->stream($messages, $tools, $options) as $chunk) {
            if ($chunk->reasoning !== '') {
                yield new ReasoningDelta($chunk->reasoning);
            }

            if ($chunk->content !== '') {
                yield new TextDelta($chunk->content);
            }

            foreach ($chunk->toolCalls as $call) {
                $index = $callCount++;
                yield new ToolInputStart($index, $call->id, $call->name);
                yield new ToolInputDelta($index, $call->id, self::encodeToolArguments($call->arguments));
                yield new ToolInputEnd($index, $call->id, $call->name, $call->arguments, $call->metadata);
            }

            if ($chunk->usage !== null) {
                $usage = $usage === null ? $chunk->usage : self::maxUsage($usage, $chunk->usage);
            }

            if ($chunk->model !== '') {
                $model = $chunk->model;
            }

            if ($chunk->contentBlocks !== []) {
                $contentBlocks = $chunk->contentBlocks;
            }

            $failed = $reason !== null && $reason !== ProviderFinishReason::ToolUse;
            if (!$failed && $chunk->finishReason !== ProviderFinishReason::Stop) {
                $reason = $chunk->finishReason;
            }
        }

        yield new Finish(
            reason: $reason ?? ($callCount > 0 ? ProviderFinishReason::ToolUse : ProviderFinishReason::Stop),
            rawReason: '',
            usage: $usage,
            model: $model,
            contentBlocks: $contentBlocks,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function encodeToolArguments(array $arguments): string
    {
        return (string) json_encode(
            (object) $arguments,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
    }

    private static function maxUsage(Usage $a, Usage $b): Usage
    {
        $prompt = max($a->promptTokens, $b->promptTokens);
        $completion = max($a->completionTokens, $b->completionTokens);

        return new Usage(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: max($a->totalTokens, $b->totalTokens, $prompt + $completion),
            cacheReadTokens: self::maxReported($a->cacheReadTokens, $b->cacheReadTokens),
            cacheWriteTokens: self::maxReported($a->cacheWriteTokens, $b->cacheWriteTokens),
            reasoningTokens: self::maxReported($a->reasoningTokens, $b->reasoningTokens),
        );
    }

    private static function maxReported(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }

        return $b === null ? $a : max($a, $b);
    }
}
