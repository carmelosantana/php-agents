<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class NumberParameter extends Parameter
{
    public function __construct(
        string $name,
        string $description,
        bool $required = true,
        public bool $integer = false,
        public ?float $minimum = null,
        public ?float $maximum = null,
    ) {
        parent::__construct($name, $description, $required);
    }

    public function toSchema(): array
    {
        $schema = [
            'type' => $this->integer ? 'integer' : 'number',
            'description' => $this->description,
        ];

        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }
        if ($this->maximum !== null) {
            $schema['maximum'] = $this->maximum;
        }

        return $schema;
    }

    public function validate(mixed $value): ValidationResult
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be a number.', $this->name));
        }

        if (is_string($value)) {
            if ($this->integer) {
                if (!preg_match('/^-?\d+$/', $value)) {
                    return ValidationResult::failure(sprintf('Parameter "%s" must be an integer.', $this->name));
                }

                $value = (int) $value;
            } else {
                if (!is_numeric($value)) {
                    return ValidationResult::failure(sprintf('Parameter "%s" must be a number.', $this->name));
                }

                $value = (float) $value;
            }
        }

        if ($this->integer) {
            if (is_float($value) && floor($value) !== $value) {
                return ValidationResult::failure(sprintf('Parameter "%s" must be an integer.', $this->name));
            }

            $value = (int) $value;
        }

        if ($this->minimum !== null && $value < $this->minimum) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be at least %s.', $this->name, (string) $this->minimum));
        }

        if ($this->maximum !== null && $value > $this->maximum) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be at most %s.', $this->name, (string) $this->maximum));
        }

        return ValidationResult::success($value);
    }
}
