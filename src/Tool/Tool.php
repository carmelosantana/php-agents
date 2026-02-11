<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\Parameter;
use Closure;

final class Tool implements ToolInterface
{
    /**
     * @param Parameter[] $parameters
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $parameters,
        private readonly Closure $callback,
        private readonly int $maxTries = 3,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    public function maxTries(): int
    {
        return $this->maxTries;
    }

    public function execute(array $input): ToolResult
    {
        try {
            $result = ($this->callback)($input);

            if ($result instanceof ToolResult) {
                return $result;
            }

            return ToolResult::success(is_string($result) ? $result : (json_encode($result) ?: ''));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toFunctionSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $param) {
            $properties[$param->name] = $param->toSchema();

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
