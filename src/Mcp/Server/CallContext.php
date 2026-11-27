<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

final readonly class CallContext
{
    /** @param array<string, mixed> $user Authenticated identity supplied by the host. */
    public function __construct(
        public array $user,
        public string $profile,
    ) {}
}
