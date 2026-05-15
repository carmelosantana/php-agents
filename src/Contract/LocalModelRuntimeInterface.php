<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

/**
 * Public entry point for local model runtimes.
 */
interface LocalModelRuntimeInterface
{
    /**
     * @return RuntimeModelMetadata[]
     */
    public function models(): array;

    public function isAvailable(): bool;

    /**
     * @param array<string, mixed> $options
     */
    public function open(string $model, array $options = []): LocalModelHandleInterface;
}