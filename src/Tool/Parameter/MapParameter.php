<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class MapParameter extends Parameter
{
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
}