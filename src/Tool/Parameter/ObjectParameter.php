<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class ObjectParameter extends Parameter
{
    /**
     * @param Parameter[] $properties
     */
    public function __construct(
        string $name,
        string $description,
        bool $required = true,
        public array $properties = [],
    ) {
        parent::__construct($name, $description, $required);
    }

    public function toSchema(): array
    {
        $props = [];
        $required = [];

        foreach ($this->properties as $param) {
            $props[$param->name] = $param->toSchema();
            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return [
            'type' => 'object',
            'description' => $this->description,
            'properties' => $props,
            'required' => $required,
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

        $validated = $value;
        $missing = [];

        foreach ($this->properties as $param) {
            if ($param->required && !array_key_exists($param->name, $value)) {
                $missing[] = $param->name;
                continue;
            }

            if (!array_key_exists($param->name, $value)) {
                continue;
            }

            $result = $param->validate($value[$param->name]);
            if (!$result->valid) {
                return ValidationResult::failure(sprintf(
                    'Parameter "%s.%s" is invalid: %s',
                    $this->name,
                    $param->name,
                    $result->error ?? 'Invalid value.',
                ));
            }

            $validated[$param->name] = $result->value;
        }

        if ($missing !== []) {
            return ValidationResult::failure(sprintf(
                'Parameter "%s" is missing required properties: %s.',
                $this->name,
                implode(', ', $missing),
            ));
        }

        return ValidationResult::success($validated);
    }
}
