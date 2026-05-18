<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStateSnapshot;

/**
 * Handle for a loaded local model instance.
 */
interface LocalModelHandleInterface
{
    public function model(): RuntimeModelMetadata;

    /**
     * @return int[]
     */
    public function tokenize(string $text, bool $addSpecial = true, bool $parseSpecial = false): array;

    /**
     * @param int[] $tokens
     */
    public function detokenize(array $tokens, bool $removeSpecial = true, bool $unparseSpecial = false): string;

    public function generate(RuntimeCompletionRequest $request): RuntimeCompletionResult;

    /**
     * @return iterable<RuntimeCompletionChunk>
     */
    public function stream(RuntimeCompletionRequest $request): iterable;

    public function snapshotState(): RuntimeStateSnapshot;

    public function restoreState(RuntimeStateSnapshot $snapshot): void;

    public function snapshotSequenceState(string $sequenceId): RuntimeStateSnapshot;

    public function restoreSequenceState(string $sequenceId, RuntimeStateSnapshot $snapshot): void;

    public function close(): void;
}