<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

final readonly class Tool
{
    /**
     * The handler has signature `\Closure(array $args, CallContext $ctx): mixed`.
     *
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed>|null $outputSchema
     * @param array<string, bool> $annotations
     * @param list<string> $profiles
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public \Closure $handler,
        public ?array $outputSchema = null,
        public array $annotations = [
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
        ],
        public array $profiles = [],
        public bool $hot = false,
    ) {}

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $definition = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => $this->annotations === [] ? new \stdClass() : $this->annotations,
        ];

        if ($this->outputSchema !== null) {
            $definition['outputSchema'] = $this->outputSchema;
        }
        if ($this->hot) {
            $definition['_meta'] = ['anthropic/alwaysLoad' => true];
        }

        return $definition;
    }
}
