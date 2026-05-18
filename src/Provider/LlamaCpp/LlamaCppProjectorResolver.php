<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

final class LlamaCppProjectorResolver
{
    /**
     * @param array<string, mixed> $options
     */
    public function resolve(RuntimeModelMetadata $metadata, array $options = []): string
    {
        $explicit = $options['modelProjectorPath'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if ($metadata->projectorPath !== null && $metadata->projectorPath !== '') {
            return $metadata->projectorPath;
        }

        $discovered = $this->discoverColocatedProjector($metadata->path);
        if ($discovered !== null) {
            return $discovered;
        }

        throw new \RuntimeException(
            "Model {$metadata->id} requires a projector for image input, but no projector path was configured or discovered.",
        );
    }

    private function discoverColocatedProjector(string $modelPath): ?string
    {
        if ($modelPath === '') {
            return null;
        }

        $directory = dirname($modelPath);
        $filename = basename($modelPath);
        $stem = preg_replace('/\.gguf$/i', '', $filename) ?? $filename;

        $candidates = [
            $directory . '/' . $stem . '.mmproj',
            $directory . '/' . $stem . '.mmproj.gguf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $globbed = glob($directory . '/*.mmproj*') ?: [];
        if (count($globbed) === 1 && is_file($globbed[0])) {
            return $globbed[0];
        }

        return null;
    }
}