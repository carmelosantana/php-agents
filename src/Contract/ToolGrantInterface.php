<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * A host tool that discovers other tools (a tool search, for example) and makes them executable.
 *
 * AbstractAgent executes only the tools it advertised to the provider (capped by maxTools). After
 * a call to a tool that implements this interface runs this round and returns a non-error result,
 * the agent reads grantedToolNames() and adds every name that exists among its registered tools
 * to the executable set for the rest of that run. Names it does not know are ignored. Grants do
 * not carry over to the next run().
 *
 * Implement it only on trusted host code: the names it returns widen what the model may execute.
 */
interface ToolGrantInterface
{
    /**
     * @return list<string> Tool names to make executable for the rest of the run.
     */
    public function grantedToolNames(): array;
}
