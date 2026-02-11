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
}
