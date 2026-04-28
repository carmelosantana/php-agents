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
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }

            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be an object.', $this->name));
        }

        return ValidationResult::success($value);
    }
}