<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class ValidationResult
{
    private function __construct(
        public bool $valid,
        public mixed $value = null,
        public ?string $error = null,
    ) {}

    public static function success(mixed $value): self
    {
        return new self(true, $value);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}