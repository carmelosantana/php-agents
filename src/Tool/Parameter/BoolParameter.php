<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool\Parameter;

final readonly class BoolParameter extends Parameter
{
    public function toSchema(): array
    {
        return [
            'type' => 'boolean',
            'description' => $this->description,
        ];
    }
}
