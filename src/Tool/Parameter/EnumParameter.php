<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class EnumParameter extends Parameter
{
    /**
     * @param string[] $values Allowed values
     */
    public function __construct(
        string $name,
        string $description,
        public array $values,
        bool $required = true,
    ) {
        parent::__construct($name, $description, $required);
    }

    public function toSchema(): array
    {
        return [
            'type' => 'string',
            'description' => $this->description,
            'enum' => $this->values,
        ];
    }
}
