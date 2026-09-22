<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/** The server refused the configured credentials (HTTP 401 or 403). */
final class McpAuthException extends McpException
{
    public function __construct(string $method, public readonly int $status)
    {
        parent::__construct(sprintf('MCP %s was refused with HTTP %d; check the configured credentials.', $method, $status));
    }
}
