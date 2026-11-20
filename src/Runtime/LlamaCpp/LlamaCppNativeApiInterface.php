<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

/**
 * @deprecated 0.18 Use \CarmeloSantana\PHPAgents\Provider\LlamaServerProvider (llama.cpp's llama-server).
 *             The FFI binding matches llama.h only up to llama.cpp b9174 (newer builds segfault)
 *             and, with tools (always under Agent), buffers the whole generation. Removed or
 *             fixed in 0.19.
 */
interface LlamaCppNativeApiInterface
{
    public function isAvailable(): bool;

    public function backendInit(): void;

    /**
     * @param array<string, mixed> $options
     */
    public function openModel(string $path, array $options = []): object;

    /**
     * @param array<string, mixed> $options
     */
    public function describeModel(object $model, string $fallbackId, string $path, array $options = []): RuntimeModelMetadata;

    /**
     * @param array<string, mixed> $options
     */
    public function openContext(object $model, array $options = []): object;

    public function closeContext(object $context): void;

    public function closeModel(object $model): void;

    /**
     * @return int[]
     */
    public function tokenize(object $model, string $text, bool $addSpecial = true, bool $parseSpecial = false): array;

    /**
     * @param int[] $tokens
     */
    public function detokenize(object $model, array $tokens, bool $removeSpecial = true, bool $unparseSpecial = false): string;

    public function generate(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
    ): RuntimeCompletionResult;

    /**
     * @return iterable<RuntimeCompletionChunk>
     */
    public function stream(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
    ): iterable;

    public function snapshotState(object $context): string;

    public function restoreState(object $context, string $bytes): void;

    public function snapshotSequenceState(object $context, int $sequenceId): string;

    public function restoreSequenceState(object $context, int $sequenceId, string $bytes): void;
}