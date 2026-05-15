<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime;

use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

final readonly class RuntimeCompletionResult
{
    /**
     * @param ToolCall[] $toolCalls
     * @param array<string, mixed> $metadata
     * @param string[] $warnings
     */
    public function __construct(
        public string $content,
        public string $reasoning,
        public array $toolCalls,
        public RuntimeFinishReason $finishReason,
        public ?Usage $usage = null,
        public array $metadata = [],
        public array $warnings = [],
    ) {}

    /**
     * @param iterable<RuntimeCompletionChunk> $chunks
     */
    public static function fromChunks(iterable $chunks): self
    {
        $content = '';
        $reasoning = '';
        $toolCalls = [];
        $finishReason = RuntimeFinishReason::Stop;
        $usage = null;
        $metadata = [];
        $warnings = [];

        foreach ($chunks as $chunk) {
            $content .= $chunk->content;
            $reasoning .= $chunk->reasoning;
            if ($chunk->toolCalls !== []) {
                $toolCalls = [...$toolCalls, ...$chunk->toolCalls];
            }
            if ($chunk->finishReason !== null) {
                $finishReason = $chunk->finishReason;
            }
            if ($chunk->usage !== null) {
                $usage = $chunk->usage;
            }
            if ($chunk->metadata !== []) {
                $metadata = array_replace_recursive($metadata, $chunk->metadata);
            }
            if ($chunk->warnings !== []) {
                $warnings = [...$warnings, ...$chunk->warnings];
            }
        }

        return new self(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $usage,
            metadata: $metadata,
            warnings: $warnings,
        );
    }
}