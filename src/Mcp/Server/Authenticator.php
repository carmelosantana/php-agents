<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

interface Authenticator
{
    /**
     * @return array<string, mixed>|null The authenticated identity for CallContext, or null for rejected credentials.
     */
    public function authenticate(string $user, string $token): ?array;
}
