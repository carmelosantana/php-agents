<?php

declare(strict_types=1);

namespace Tests\Support\Agent;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamProjector;

/**
 * Yields one scripted list of stream events per streamEvents() call; stream() is their
 * projection. Running out of scripts throws, as a provider failure would.
 */
final class ScriptedEventsProvider implements ProviderInterface
{
    public int $calls = 0;

    /** @param list<list<StreamEvent>> $scripts */
    public function __construct(private array $scripts) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new \LogicException('chat() is not used by the agent loop.');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        return StreamProjector::toResponses($this->streamEvents($messages, $tools, $options));
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls++;

        yield from array_shift($this->scripts) ?? throw new \RuntimeException('No scripted events left.');
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
