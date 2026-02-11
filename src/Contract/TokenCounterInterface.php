<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Counts tokens for pre-flight context window estimation.
 */
interface TokenCounterInterface
{
    /**
     * Count tokens in a text string.
     */
    public function count(string $text): int;

    /**
     * Count tokens for an array of messages.
     *
     * @param MessageInterface[] $messages
     */
    public function countMessages(array $messages): int;

    /**
     * Count tokens for tool definitions.
     *
     * @param ToolInterface[] $tools
     */
    public function countTools(array $tools): int;

    /**
     * The encoding name this counter uses.
     */
    public function encoding(): string;
}
