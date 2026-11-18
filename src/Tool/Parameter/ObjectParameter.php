<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

use CarmeloSantana\PHPAgents\Tool\UnknownKeys;

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

        $schema = [
            'type' => 'object',
            'description' => $this->description,
            'properties' => $props,
            'required' => $required,
        ];

        // With no declared properties this stays a free-form object.
        if ($this->properties !== []) {
            $schema['additionalProperties'] = false;
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
            return ValidationResult::failure(sprintf('Parameter "%s" must be an object.', $this->name));
        }

        // A JSON array (or `{"0":…}`, which decodes the same) is not an object. `{}`
        // decodes to [], so the empty list stays accepted.
        if ($this->properties !== [] && $value !== [] && array_is_list($value)) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be an object.', $this->name));
        }

        if ($this->properties !== []) {
            $declared = array_map(static fn(Parameter $param): string => $param->name, $this->properties);
            $unknown = array_diff(array_map('strval', array_keys($value)), $declared);

            if ($unknown !== []) {
                return ValidationResult::failure(sprintf(
                    'Parameter "%s" has unknown properties: %s.',
                    $this->name,
                    UnknownKeys::format($unknown),
                ));
            }
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
