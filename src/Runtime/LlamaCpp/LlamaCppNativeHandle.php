<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime\LlamaCpp;

use CarmeloSantana\PHPAgents\Contract\LocalModelHandleInterface;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStateSnapshot;

final class LlamaCppNativeHandle implements LocalModelHandleInterface
{
    private bool $closed = false;

    public function __construct(
        private readonly RuntimeModelMetadata $metadata,
        private readonly object $model,
        private readonly object $context,
        private readonly LlamaCppNativeApiInterface $api,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function model(): RuntimeModelMetadata
    {
        $this->assertOpen();

        return $this->metadata;
    }

    public function tokenize(string $text, bool $addSpecial = true, bool $parseSpecial = false): array
    {
        $this->assertOpen();

        return $this->api->tokenize($this->model, $text, $addSpecial, $parseSpecial);
    }

    public function detokenize(array $tokens, bool $removeSpecial = true, bool $unparseSpecial = false): string
    {
        $this->assertOpen();

        return $this->api->detokenize($this->model, $tokens, $removeSpecial, $unparseSpecial);
    }

    public function generate(RuntimeCompletionRequest $request): RuntimeCompletionResult
    {
        $this->assertOpen();

        return $this->api->generate($this->model, $this->context, $this->metadata, $request);
    }

    public function stream(RuntimeCompletionRequest $request): iterable
    {
        $this->assertOpen();

        return $this->api->stream($this->model, $this->context, $this->metadata, $request);
    }

    public function snapshotState(): RuntimeStateSnapshot
    {
        $this->assertOpen();

        return new RuntimeStateSnapshot($this->api->snapshotState($this->context));
    }

    public function restoreState(RuntimeStateSnapshot $snapshot): void
    {
        $this->assertOpen();

        $this->api->restoreState($this->context, $snapshot->bytes);
    }

    public function snapshotSequenceState(string $sequenceId): RuntimeStateSnapshot
    {
        $this->assertOpen();

        return new RuntimeStateSnapshot(
            $this->api->snapshotSequenceState($this->context, self::mapSequenceId($sequenceId)),
            $sequenceId,
        );
    }

    public function restoreSequenceState(string $sequenceId, RuntimeStateSnapshot $snapshot): void
    {
        $this->assertOpen();

        $this->api->restoreSequenceState($this->context, self::mapSequenceId($sequenceId), $snapshot->bytes);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->api->closeContext($this->context);
        } finally {
            $this->api->closeModel($this->model);
            $this->closed = true;
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Native llama.cpp handle is closed.');
        }
    }

    private static function mapSequenceId(string $sequenceId): int
    {
        return (int) (sprintf('%u', crc32($sequenceId)) % 2147483647);
    }
}