<?php

declare(strict_types=1);

namespace Tests\Support\Runtime\Native;

use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeApiInterface;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

final class FakeLlamaCppNativeApi implements LlamaCppNativeApiInterface
{
    public bool $available = true;

    public bool $backendInitialized = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $openModelCalls = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $openContextCalls = [];

    /**
     * @var list<string>
     */
    public array $closedModels = [];

    /**
     * @var list<int>
     */
    public array $closedContexts = [];

    private int $nextContextId = 1;

    /**
     * @var array<string, RuntimeModelMetadata>
     */
    private array $metadataByPath = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitionsByPath = [];

    /**
     * @var array<int, string>
     */
    private array $statesByContext = [];

    /**
     * @var array<int, array<int, string>>
     */
    private array $sequenceStatesByContext = [];

    public ?RuntimeCompletionRequest $lastCompletionRequest = null;

    /**
     * @param array<string, mixed> $definition
     */
    public function registerModel(string $path, RuntimeModelMetadata $metadata, array $definition = []): void
    {
        $this->metadataByPath[$path] = $metadata;
        $this->definitionsByPath[$path] = $definition;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function backendInit(): void
    {
        $this->backendInitialized = true;
    }

    public function openModel(string $path, array $options = []): object
    {
        if (!$this->available) {
            throw new \RuntimeException('Fake native API is unavailable.');
        }

        if (!isset($this->metadataByPath[$path])) {
            throw new \InvalidArgumentException("Unknown fake native model path: {$path}");
        }

        $this->backendInit();
        $this->openModelCalls[] = ['path' => $path, 'options' => $options];

        return (object) ['path' => $path];
    }

    public function describeModel(object $model, string $fallbackId, string $path, array $options = []): RuntimeModelMetadata
    {
        return $this->metadataByPath[$path] ?? new RuntimeModelMetadata(
            id: $fallbackId,
            name: basename($path),
            path: $path,
            maxTokens: 2048,
        );
    }

    public function openContext(object $model, array $options = []): object
    {
        $context = (object) ['id' => $this->nextContextId++, 'path' => $model->path];
        $this->openContextCalls[] = ['path' => $model->path, 'options' => $options, 'contextId' => $context->id];
        $definition = $this->definitionsByPath[$model->path] ?? [];
        $this->statesByContext[$context->id] = (string) ($definition['state'] ?? '');
        $this->sequenceStatesByContext[$context->id] = $definition['sequenceStates'] ?? [];

        return $context;
    }

    public function closeContext(object $context): void
    {
        $this->closedContexts[] = $context->id;
    }

    public function closeModel(object $model): void
    {
        $this->closedModels[] = $model->path;
    }

    public function tokenize(object $model, string $text, bool $addSpecial = true, bool $parseSpecial = false): array
    {
        $configured = $this->definitionsByPath[$model->path]['tokens'][$text] ?? null;
        if (is_array($configured)) {
            return array_values(array_map(static fn(mixed $token): int => (int) $token, $configured));
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(static fn(string $char): int => ord($char), $chars));
    }

    public function detokenize(object $model, array $tokens, bool $removeSpecial = true, bool $unparseSpecial = false): string
    {
        $key = implode(',', array_map(static fn(int $token): string => (string) $token, $tokens));
        $configured = $this->definitionsByPath[$model->path]['detokenized'][$key] ?? null;
        if (is_string($configured)) {
            return $configured;
        }

        return implode('', array_map(static fn(int $token): string => chr($token), $tokens));
    }

    public function generate(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
    ): RuntimeCompletionResult {
        return RuntimeCompletionResult::fromChunks($this->stream($model, $context, $metadata, $request));
    }

    public function stream(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
    ): iterable {
        $this->lastCompletionRequest = $request;

        if ($request->images !== [] && !$metadata->supportsVision) {
            throw new \InvalidArgumentException("Model {$metadata->id} does not support image input.");
        }

        if ($request->structuredOutput?->strict) {
            $modes = $metadata->extras['structuredOutputModes'] ?? [];
            $supportsStructured = (bool) ($metadata->extras['supportsStructuredOutput'] ?? ($modes !== []));
            if (!$supportsStructured || (is_array($modes) && !in_array('json_schema', $modes, true))) {
                throw new \RuntimeException("Strict structured output mode 'json_schema' is not supported for model {$metadata->id}.");
            }
        }

        $definition = $this->definitionsByPath[$model->path] ?? [];
        $responses = $definition['responses'] ?? [];
        $chunks = $responses[$request->prompt] ?? $responses['*'] ?? [new RuntimeCompletionChunk(content: '')];

        foreach ($chunks as $chunk) {
            if ($chunk instanceof RuntimeCompletionChunk) {
                yield $chunk;
                continue;
            }

            yield new RuntimeCompletionChunk(content: (string) $chunk);
        }

        if ($chunks === [] || end($chunks) instanceof RuntimeCompletionChunk && end($chunks)->finishReason !== null) {
            return;
        }

        yield new RuntimeCompletionChunk(finishReason: RuntimeFinishReason::Stop);
    }

    public function snapshotState(object $context): string
    {
        return $this->statesByContext[$context->id] ?? '';
    }

    public function restoreState(object $context, string $bytes): void
    {
        $this->statesByContext[$context->id] = $bytes;
    }

    public function snapshotSequenceState(object $context, int $sequenceId): string
    {
        return $this->sequenceStatesByContext[$context->id][$sequenceId] ?? '';
    }

    public function restoreSequenceState(object $context, int $sequenceId, string $bytes): void
    {
        $this->sequenceStatesByContext[$context->id][$sequenceId] = $bytes;
    }
}