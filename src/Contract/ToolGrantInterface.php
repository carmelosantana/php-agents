<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

/**
 * A host tool that discovers other tools (a tool search, for example) and makes them executable.
 *
 * AbstractAgent executes only the tools it advertised to the provider (capped by maxTools). After
 * a call to a tool that implements this interface runs this round and returns a successful result,
 * the agent reads grantedToolNames() and adds every name that exists among its registered tools
 * to the executable set for the rest of that run. Names it does not know are ignored. Grants do
 * not carry over to the next run().
 *
 * Implement it only on trusted host code: the names it returns widen what the model may execute.
 */
interface ToolGrantInterface
{
    /**
     * The names granted by this tool's most recent call; the agent reads them right after a
     * successful call, so return only what that call found, not every name granted so far.
     *
     * @return list<string> Tool names to make executable for the rest of the run.
     */
    public function grantedToolNames(): array;
}
