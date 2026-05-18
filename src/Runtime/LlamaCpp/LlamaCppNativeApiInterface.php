<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

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