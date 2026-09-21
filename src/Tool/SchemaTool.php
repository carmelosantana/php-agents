<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Schema\JsonSchemaRepair;

/**
 * A tool whose input is described by a raw JSON Schema instead of typed Parameters.
 *
 * Tool builds its schema from Parameter objects and validates the model's arguments
 * against them in Tool::execute(). A schema written elsewhere (an MCP server's
 * `inputSchema`) has constructs no Parameter models (`oneOf`, `$ref`, formats), and
 * the party that published it validates its own input. So:
 *
 * - toFunctionSchema() returns the schema, passed through JsonSchemaRepair, in the
 *   OpenAI function shape every provider reads. A schema with no `type` gets
 *   `type: object`, which is the only root type a function's parameters may have.
 * - parameters() returns []. SystemPrompt::withTools() then renders the tool's name
 *   and description with no parameters block, and the arguments reach the model
 *   through the provider's tool schema.
 * - execute() calls the closure and does not validate. A throw, or a return value
 *   that is not a ToolResult, becomes an error naming the tool and not quoting the
 *   exception: a tool result is text the model reads and may repeat, and a
 *   transport exception can quote an endpoint.
 */
final class SchemaTool implements ToolInterface
{
    /**
     * @param array<string, mixed> $schema
     * @param \Closure(array<string, mixed>): ToolResult $run
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $schema,
        private readonly \Closure $run,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<never, never> */
    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        try {
            /** @var mixed $result */
            $result = ($this->run)($input);
        } catch (\Throwable) {
            return $this->failure();
        }

        return $result instanceof ToolResult ? $result : $this->failure();
    }

    public function toFunctionSchema(): array
    {
        $schema = $this->schema;
        $schema['type'] ??= 'object';

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => JsonSchemaRepair::repair($schema),
            ],
        ];
    }

    private function failure(): ToolResult
    {
        return ToolResult::error(sprintf('The %s tool failed before it could answer.', $this->name));
    }
}
