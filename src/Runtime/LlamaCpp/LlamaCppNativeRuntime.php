<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime\LlamaCpp;

use CarmeloSantana\PHPAgents\Contract\LocalModelHandleInterface;
use CarmeloSantana\PHPAgents\Contract\LocalModelRuntimeInterface;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

final class LlamaCppNativeRuntime implements LocalModelRuntimeInterface
{
    /**
     * @var array<string, RuntimeModelMetadata>
     */
    private array $registeredModels = [];

    /**
     * @param RuntimeModelMetadata[] $models
     * @param array<string, mixed> $runtimeOptions
     */
    public function __construct(
        private readonly LlamaCppNativeApiInterface $nativeApi,
        array $models = [],
        private readonly array $runtimeOptions = [],
    ) {
        foreach ($models as $model) {
            $this->registerModel($model);
        }
    }

    public function models(): array
    {
        $unique = [];

        foreach ($this->registeredModels as $metadata) {
            $unique[$metadata->id] = $metadata;
        }

        return array_values($unique);
    }

    public function isAvailable(): bool
    {
        return $this->nativeApi->isAvailable();
    }

    public function open(string $model, array $options = []): LocalModelHandleInterface
    {
        if (!$this->nativeApi->isAvailable()) {
            throw new \RuntimeException('Native llama.cpp runtime is unavailable.');
        }

        [$registeredMetadata, $modelPath] = $this->resolveModelPath($model);
        $resolvedOptions = [...$this->runtimeOptions, ...$options];
        $resolvedModelId = $registeredMetadata instanceof RuntimeModelMetadata ? $registeredMetadata->id : basename($modelPath);
        $resolvedAliases = $registeredMetadata instanceof RuntimeModelMetadata ? $registeredMetadata->aliases : [];

        $nativeModel = $this->nativeApi->openModel($modelPath, $resolvedOptions);

        try {
            $discoveredMetadata = $this->nativeApi->describeModel(
                $nativeModel,
                $resolvedModelId,
                $modelPath,
                [...$resolvedOptions, 'aliases' => $resolvedAliases],
            );
            $metadata = $this->mergeMetadata($registeredMetadata, $discoveredMetadata, $modelPath);
            $context = $this->nativeApi->openContext($nativeModel, $resolvedOptions);
        } catch (\Throwable $e) {
            $this->nativeApi->closeModel($nativeModel);
            throw $e;
        }

        return new LlamaCppNativeHandle($metadata, $nativeModel, $context, $this->nativeApi);
    }

    private function registerModel(RuntimeModelMetadata $metadata): void
    {
        $this->registeredModels[$metadata->id] = $metadata;

        foreach ($metadata->aliases as $alias) {
            $this->registeredModels[$alias] = $metadata;
        }
    }

    /**
     * @return array{0: ?RuntimeModelMetadata, 1: string}
     */
    private function resolveModelPath(string $model): array
    {
        $metadata = $this->registeredModels[$model] ?? null;
        if ($metadata !== null) {
            if ($metadata->path === '') {
                throw new \InvalidArgumentException("Native llama.cpp model '{$model}' is missing a model path.");
            }

            return [$metadata, $metadata->path];
        }

        if (is_file($model)) {
            return [null, $model];
        }

        throw new \InvalidArgumentException("Unknown native llama.cpp model: {$model}");
    }

    private function mergeMetadata(?RuntimeModelMetadata $configured, RuntimeModelMetadata $discovered, string $modelPath): RuntimeModelMetadata
    {
        if ($configured === null) {
            return $discovered;
        }

        return new RuntimeModelMetadata(
            id: $configured->id,
            name: $configured->name !== '' ? $configured->name : $discovered->name,
            path: $configured->path !== '' ? $configured->path : $modelPath,
            family: $configured->family ?? $discovered->family,
            contextWindow: $configured->contextWindow,
            maxTokens: $configured->maxTokens,
            supportsTools: $configured->supportsTools,
            supportsVision: $configured->supportsVision,
            supportsReasoning: $configured->supportsReasoning,
            supportsThinking: $configured->supportsThinking,
            projectorPath: $configured->projectorPath ?? $discovered->projectorPath,
            defaultTemplate: $configured->defaultTemplate ?? $discovered->defaultTemplate,
            defaultToolParser: $configured->defaultToolParser ?? $discovered->defaultToolParser,
            aliases: $configured->aliases,
            extras: [...$discovered->extras, ...$configured->extras],
        );
    }
}