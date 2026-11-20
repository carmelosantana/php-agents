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
 * projection. A Throwable in a script is thrown at that point, a Closure is called there (to act
 * between two reads, as a transport would) and yields nothing, and running out of scripts
 * throws, as a provider failure would. $closedStreams counts the generators whose `finally` ran,
 * so a test can see that the agent abandoned a stream. $options records the options of each
 * streamEvents() call.
 */
final class ScriptedEventsProvider implements ProviderInterface
{
    public int $calls = 0;

    public int $closedStreams = 0;

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /** @param list<list<StreamEvent|\Throwable|\Closure(): mixed>> $scripts */
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
        $this->options[] = $options;

        $script = array_shift($this->scripts) ?? throw new \RuntimeException('No scripted events left.');

        try {
            foreach ($script as $event) {
                if ($event instanceof \Closure) {
                    $event();
                    continue;
                }

                yield $event instanceof \Throwable ? throw $event : $event;
            }
        } finally {
            $this->closedStreams++;
        }
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
