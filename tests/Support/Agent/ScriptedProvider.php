<?php

declare(strict_types=1);

namespace Tests\Support\Agent;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * Yields one scripted Response per stream() call and records what the agent sent.
 */
final class ScriptedProvider implements ProviderInterface
{
    /** @var list<array{messages: array<mixed>, tools: list<string>}> */
    public array $calls = [];

    /** @param list<Response> $scripts */
    public function __construct(private array $scripts) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new \LogicException('chat() is not used by the agent loop.');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls[] = [
            'messages' => $messages,
            'tools' => array_map(static fn(ToolInterface $tool): string => $tool->name(), array_values($tools)),
        ];

        yield array_shift($this->scripts) ?? throw new \RuntimeException('No scripted response left.');
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        throw new \LogicException('structured() is not used by the agent loop.');
    }

    public function models(): array
    {
        return [new ModelDefinition(id: 'test-model', name: 'Test', provider: 'test', capabilities: [ModelCapability::Text, ModelCapability::Tools])];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return 'test-model';
    }

    public function withModel(string $model): static
    {
        return $this;
    }
}
