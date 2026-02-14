<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Message\Conversation;
use SplSubject;

/**
 * The core contract every agent must implement.
 */
interface AgentInterface extends SplSubject
{
    /**
     * System prompt instructions for this agent.
     */
    public function instructions(): string;

    /**
     * Tools available to this agent during execution.
     *
     * @return ToolInterface[]
     */
    public function tools(): array;

    /**
     * The LLM provider this agent uses.
     */
    public function provider(): ProviderInterface;

    /**
     * Execute the agent with the given input.
     *
     * Prior conversation history can be injected via $history to enable
     * multi-turn conversations. System messages in $history are skipped
     * (the agent builds its own system prompt).
     */
    public function run(MessageInterface $input, ?Conversation $history = null): Output;

    /**
     * Maximum iterations before forced termination.
     */
    public function maxIterations(): int;

    /**
     * Model capabilities this agent requires.
     *
     * @return ModelCapability[]
     */
    public function requiredCapabilities(): array;
}
