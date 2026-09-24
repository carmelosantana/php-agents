<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * What McpClient remembers about a server between PHP requests: the protocol version
 * it detected, and for a 2025-11-25 server the `Mcp-Session-Id` it was issued, if any.
 * The session id is a credential for that session, so store it as one.
 */
final readonly class McpSession
{
    public function __construct(
        public string $protocolVersion,
        public ?string $sessionId = null,
    ) {}
}
