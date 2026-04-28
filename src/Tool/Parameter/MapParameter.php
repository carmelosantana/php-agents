<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class MapParameter extends Parameter
{
    /**
     * @param bool|array<string, mixed> $additionalProperties
     */
    public function __construct(
        string $name,
        string $description,
        bool $required = true,
        public bool|array $additionalProperties = true,
    ) {
        parent::__construct($name, $description, $required);
    }

    public function toSchema(): array
    {
        return [
            'type' => 'object',
            'description' => $this->description,
            'additionalProperties' => $this->additionalProperties,
        ];
    }

    public function validate(mixed $value): ValidationResult
    {
        if (!is_array($value)) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be an object.', $this->name));
        }

        return ValidationResult::success($value);
    }
}