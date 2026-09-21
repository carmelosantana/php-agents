<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * The server answered with a 3xx. The client never follows redirects: a redirect
 * could send a pinned, SSRF-checked request (and its credential) somewhere else.
 * The Location is kept on the exception for the host to log, not put in the message.
 */
final class McpRedirectException extends McpTransportException
{
    public function __construct(string $method, public readonly int $status, public readonly ?string $location)
    {
        parent::__construct(sprintf('MCP %s returned HTTP %d; redirects are not followed.', $method, $status));
    }
}
