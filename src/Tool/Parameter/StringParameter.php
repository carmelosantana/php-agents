<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class StringParameter extends Parameter
{
    /**
     * @param string[]|null $enum Constrained string values
     */
    public function __construct(
        string $name,
        string $description,
        bool $required = true,
        public ?string $pattern = null,
        public ?int $maxLength = null,
        public ?array $enum = null,
    ) {
        parent::__construct($name, $description, $required);
    }

    public function toSchema(): array
    {
        $schema = ['type' => 'string', 'description' => $this->description];

        if ($this->pattern !== null) {
            $schema['pattern'] = $this->pattern;
        }
        if ($this->maxLength !== null) {
            $schema['maxLength'] = $this->maxLength;
        }
        if ($this->enum !== null) {
            $schema['enum'] = $this->enum;
        }

        return $schema;
    }

    public function validate(mixed $value): ValidationResult
    {
        if (!is_string($value)) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be a string.', $this->name));
        }

        if ($this->maxLength !== null && mb_strlen($value) > $this->maxLength) {
            return ValidationResult::failure(sprintf('Parameter "%s" must be at most %d characters.', $this->name, $this->maxLength));
        }

        if ($this->pattern !== null && preg_match($this->pattern, $value) !== 1) {
            return ValidationResult::failure(sprintf('Parameter "%s" does not match the required pattern.', $this->name));
        }

        if ($this->enum !== null && !in_array($value, $this->enum, true)) {
            return ValidationResult::failure(sprintf(
                'Parameter "%s" must be one of: %s.',
                $this->name,
                implode(', ', $this->enum),
            ));
        }

        return ValidationResult::success($value);
    }
}
