<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class BoolParameter extends Parameter
{
    public function validate(mixed $value): ValidationResult
    {
        if (!is_bool($value)) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be a boolean.', $this->name));
        }

        return ValidationResult::success($value);
    }

    public function toSchema(): array
    {
        return [
            'type' => 'boolean',
            'description' => $this->description,
        ];
    }
}
