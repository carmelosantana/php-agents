<?php

declare(strict_types=1);

namespace Tests\Support\Mcp;

use CarmeloSantana\PHPAgents\Mcp\McpSession;
use CarmeloSantana\PHPAgents\Mcp\McpSessionStore;

final class ArraySessionStore implements McpSessionStore
{
    /** @var array<string, McpSession> */
    public array $sessions = [];

    public function load(string $key): ?McpSession
    {
        return $this->sessions[$key] ?? null;
    }

    public function save(string $key, McpSession $session): void
    {
        $this->sessions[$key] = $session;
    }

    public function forget(string $key): void
    {
        unset($this->sessions[$key]);
    }
}
