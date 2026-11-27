<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function add(Tool $tool): void
    {
        $this->assertUnusedName($tool->name);
        $this->tools[$tool->name] = $tool;
    }

    public function alias(string $name, string $target): void
    {
        $this->assertUnusedName($name);
        if (!isset($this->tools[$target])) {
            throw new \InvalidArgumentException('Alias target must be a registered tool.');
        }
        $this->aliases[$name] = $target;
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$this->aliases[$name] ?? $name] ?? null;
    }

    public function forProfile(string $profile): self
    {
        $registry = new self();
        $registry->tools = array_filter(
            $this->tools,
            static fn (Tool $tool): bool => in_array($profile, $tool->profiles, true),
        );
        $registry->aliases = array_filter(
            $this->aliases,
            static fn (string $target): bool => isset($registry->tools[$target]),
        );

        return $registry;
    }

    /** @return list<Tool> */
    public function list(): array
    {
        return array_values($this->tools);
    }

    private function assertUnusedName(string $name): void
    {
        if ($this->find($name) !== null) {
            throw new \InvalidArgumentException('Tool name is already registered.');
        }
    }
}
