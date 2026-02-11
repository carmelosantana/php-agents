<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * Groups related tools with shared configuration and guidelines.
 */
interface ToolkitInterface
{
    /**
     * All tools in this toolkit.
     *
     * @return ToolInterface[]
     */
    public function tools(): array;

    /**
     * Usage guidelines injected into the system prompt.
     */
    public function guidelines(): string;
}
