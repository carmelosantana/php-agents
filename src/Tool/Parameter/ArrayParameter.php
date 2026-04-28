<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class ArrayParameter extends Parameter
{
    public function __construct(
        string $name,
        string $description,
        bool $required = true,
        public ?Parameter $items = null,
    ) {
        parent::__construct($name, $description, $required);
    }

    public function toSchema(): array
    {
        $schema = [
            'type' => 'array',
            'description' => $this->description,
        ];

        if ($this->items !== null) {
            $schema['items'] = $this->items->toSchema();
        }

        return $schema;
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
            return ValidationResult::failure(sprintf('Parameter "%s" must be an array.', $this->name));
        }

        if ($this->items === null) {
            return ValidationResult::success($value);
        }

        $validated = [];
        foreach ($value as $index => $item) {
            $result = $this->items->validate($item);
            if (!$result->valid) {
                return ValidationResult::failure(sprintf(
                    'Parameter "%s" has an invalid item at index %d: %s',
                    $this->name,
                    $index,
                    $result->error ?? 'Invalid value.',
                ));
            }

            $validated[$index] = $result->value;
        }

        return ValidationResult::success($validated);
    }
}
