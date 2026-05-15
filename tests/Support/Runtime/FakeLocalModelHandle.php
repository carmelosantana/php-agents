<?php

declare(strict_types=1);

namespace Tests\Support\Runtime;

use CarmeloSantana\PHPAgents\Contract\LocalModelHandleInterface;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStateSnapshot;

final class FakeLocalModelHandle implements LocalModelHandleInterface
{
    private bool $closed = false;

    private RuntimeStateSnapshot $state;

    /**
     * @var array<string, RuntimeStateSnapshot>
     */
    private array $sequenceStates = [];

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        private readonly RuntimeModelMetadata $metadata,
        private readonly array $definition = [],
        private readonly ?\Closure $onRequest = null,
    ) {
        $this->state = $definition['state'] ?? new RuntimeStateSnapshot('');
        $this->sequenceStates = $definition['sequenceStates'] ?? [];
    }

    public function model(): RuntimeModelMetadata
    {
        $this->assertOpen();

        return $this->metadata;
    }

    public function tokenize(string $text, bool $addSpecial = true, bool $parseSpecial = false): array
    {
        $this->assertOpen();

        $configured = $this->definition['tokens'][$text] ?? null;
        if (is_array($configured)) {
            return array_values(array_map(static fn(mixed $token): int => (int) $token, $configured));
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(static fn(string $char): int => ord($char), $chars));
    }

    public function detokenize(array $tokens, bool $removeSpecial = true, bool $unparseSpecial = false): string
    {
        $this->assertOpen();

        $key = implode(',', array_map(static fn(int $token): string => (string) $token, $tokens));
        if (isset($this->definition['detokenized'][$key]) && is_string($this->definition['detokenized'][$key])) {
            return $this->definition['detokenized'][$key];
        }

        return implode('', array_map(static fn(int $token): string => chr($token), $tokens));
    }

    public function generate(RuntimeCompletionRequest $request): RuntimeCompletionResult
    {
        return RuntimeCompletionResult::fromChunks($this->stream($request));
    }

    public function stream(RuntimeCompletionRequest $request): iterable
    {
        $this->assertOpen();
        ($this->onRequest)?->__invoke($request);
        $this->assertRequestSupported($request);

        foreach ($this->resolveChunks($request) as $chunk) {
            yield $chunk;
        }
    }

    public function snapshotState(): RuntimeStateSnapshot
    {
        $this->assertOpen();

        return $this->state;
    }

    public function restoreState(RuntimeStateSnapshot $snapshot): void
    {
        $this->assertOpen();

        $this->state = $snapshot;
    }

    public function snapshotSequenceState(string $sequenceId): RuntimeStateSnapshot
    {
        $this->assertOpen();

        return $this->sequenceStates[$sequenceId] ?? new RuntimeStateSnapshot('', $sequenceId);
    }

    public function restoreSequenceState(string $sequenceId, RuntimeStateSnapshot $snapshot): void
    {
        $this->assertOpen();

        $this->sequenceStates[$sequenceId] = $snapshot;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @return RuntimeCompletionChunk[]
     */
    private function resolveChunks(RuntimeCompletionRequest $request): array
    {
        $responses = $this->definition['responses'] ?? [];
        $lookupOrder = array_filter([
            $request->sequenceId,
            $request->prompt,
            '*',
        ]);

        foreach ($lookupOrder as $key) {
            $configured = $responses[$key] ?? null;
            if (is_array($configured)) {
                return array_values(array_filter($configured, static fn(mixed $chunk): bool => $chunk instanceof RuntimeCompletionChunk));
            }
        }

        $chunks = $this->definition['chunks'] ?? [];

        return array_values(array_filter($chunks, static fn(mixed $chunk): bool => $chunk instanceof RuntimeCompletionChunk));
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Fake local model handle is closed.');
        }
    }

    private function assertRequestSupported(RuntimeCompletionRequest $request): void
    {
        if ($request->images !== [] && !$this->metadata->supportsVision) {
            throw new \InvalidArgumentException("Model {$this->metadata->id} does not support image input.");
        }

        if ($request->tools !== [] && !$this->metadata->supportsTools) {
            throw new \InvalidArgumentException("Model {$this->metadata->id} does not support tools.");
        }

        $supportsStructured = (bool) ($this->definition['supportsStructuredOutput'] ?? false);
        if ($request->structuredOutput !== null && !$supportsStructured) {
            throw new \RuntimeException("Model {$this->metadata->id} does not support structured output.");
        }
    }
}