<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

abstract readonly class Parameter
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $required = true,
    ) {}

    /**
     * JSON Schema fragment for this parameter.
     *
     * @return array<string, mixed>
     */
    abstract public function toSchema(): array;
}
