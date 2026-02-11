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
}
