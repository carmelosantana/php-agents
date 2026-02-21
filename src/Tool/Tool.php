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

    public function execute(array $input): ToolResult
    {
        // Validate required parameters before executing
        $missing = [];
        foreach ($this->parameters as $param) {
            if ($param->required && !array_key_exists($param->name, $input)) {
                $missing[] = $param->name;
            }
        }

        if (!empty($missing)) {
            return ToolResult::error(
                'Missing required parameters: ' . implode(', ', $missing),
            );
        }

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

        $schema = [
            'type' => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $schema,
            ],
        ];
    }
}
