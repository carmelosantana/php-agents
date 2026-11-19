<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

/**
 * A ready-to-use agent: pass instructions and tools instead of writing a subclass.
 *
 * Extend AbstractAgent when you need more than the constructor offers.
 */
final class Agent extends AbstractAgent
{
    /**
     * @param ToolInterface[] $tools
     * @param ToolkitInterface[] $toolkits
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        ProviderInterface $provider,
        private readonly string $instructions = 'You are a helpful assistant.',
        array $tools = [],
        array $toolkits = [],
        int $maxIterations = self::DEFAULT_MAX_ITERATIONS,
        ?ToolExecutionPolicyInterface $executionPolicy = null,
        ?RunLimits $runLimits = null,
        ?CancellationTokenInterface $cancellationToken = null,
        array $providerOptions = [],
    ) {
        parent::__construct(
            $provider,
            maxIter: $maxIterations,
            executionPolicy: $executionPolicy,
            cancellationToken: $cancellationToken,
            providerOptions: $providerOptions,
            runLimits: $runLimits,
        );

        foreach ($tools as $tool) {
            $this->addTool($tool);
        }

        foreach ($toolkits as $toolkit) {
            $this->addToolkit($toolkit);
        }
    }

    public function instructions(): string
    {
        return $this->instructions;
    }
}
