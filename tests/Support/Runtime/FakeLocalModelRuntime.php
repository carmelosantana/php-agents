<?php

declare(strict_types=1);

namespace Tests\Support\Runtime;

use CarmeloSantana\PHPAgents\Contract\LocalModelHandleInterface;
use CarmeloSantana\PHPAgents\Contract\LocalModelRuntimeInterface;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

final class FakeLocalModelRuntime implements LocalModelRuntimeInterface
{
    private bool $available = true;

    private ?RuntimeCompletionRequest $lastRequest = null;

    /**
     * @var array<string, RuntimeModelMetadata>
     */
    private array $models = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    /**
     * @param array<string, mixed> $definition
     */
    public function registerModel(RuntimeModelMetadata $metadata, array $definition = []): void
    {
        $this->models[$metadata->id] = $metadata;
        $this->definitions[$metadata->id] = $definition;

        foreach ($metadata->aliases as $alias) {
            $this->models[$alias] = $metadata;
            $this->definitions[$alias] = $definition;
        }
    }

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    public function models(): array
    {
        $unique = [];

        foreach ($this->models as $metadata) {
            $unique[$metadata->id] = $metadata;
        }

        return array_values($unique);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function lastRequest(): ?RuntimeCompletionRequest
    {
        return $this->lastRequest;
    }

    public function open(string $model, array $options = []): LocalModelHandleInterface
    {
        if (!$this->available) {
            throw new \RuntimeException('Fake local model runtime is unavailable.');
        }

        $metadata = $this->models[$model] ?? null;
        if ($metadata === null) {
            throw new \InvalidArgumentException("Unknown fake runtime model: {$model}");
        }

        return new FakeLocalModelHandle(
            metadata: $metadata,
            definition: $this->definitions[$model] ?? [],
            onRequest: function (RuntimeCompletionRequest $request): void {
                $this->lastRequest = $request;
            },
        );
    }
}