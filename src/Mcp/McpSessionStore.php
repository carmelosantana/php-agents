<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * Persistence for McpSession across PHP requests (a WordPress host backs it with a
 * transient). McpClient passes McpServer::sessionKey() as $key, which is opaque and
 * credential-free. How long an entry lives is the store's choice; the client calls
 * forget() when the server says the session is gone.
 */
interface McpSessionStore
{
    public function load(string $key): ?McpSession;

    public function save(string $key, McpSession $session): void;

    public function forget(string $key): void;
}
