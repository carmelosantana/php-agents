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
}
