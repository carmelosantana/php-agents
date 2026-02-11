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
}
