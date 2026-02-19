<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Provides pending user input to inject into an agent's conversation
 * between iterations.
 *
 * Implementations read from a queue (database, file, in-memory) and
 * return messages that the agent loop appends to the conversation
 * before the next LLM call. Each call consumes the returned messages
 * so they are not injected twice.
 */
interface PendingInputProviderInterface
{
    /**
     * Consume and return any pending input messages.
     *
     * Returns an array of MessageInterface objects (typically UserMessages)
     * to inject into the conversation. Once returned, the messages are
     * considered consumed and will not be returned again.
     *
     * @return MessageInterface[]
     */
    public function consumePendingInputs(): array;
}
