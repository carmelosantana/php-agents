<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class EnumParameter extends Parameter
{
    /**
     * @param string[] $values Allowed values
     */
    public function __construct(
        string $name,
        string $description,
        public array $values,
        bool $required = true,
    ) {
        parent::__construct($name, $description, $required);
    }

    public function toSchema(): array
    {
        return [
            'type' => 'string',
            'description' => $this->description,
            'enum' => $this->values,
        ];
    }

    public function validate(mixed $value): ValidationResult
    {
        if (!is_string($value)) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be a string.', $this->name));
        }

        if (!in_array($value, $this->values, true)) {
            return ValidationResult::failure(sprintf(
                'Parameter "%s" must be one of: %s.',
                $this->name,
                implode(', ', $this->values),
            ));
        }

        return ValidationResult::success($value);
    }
}
