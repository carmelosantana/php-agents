<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * A redirect the client refuses. Usually the server answered with a 3xx, and $status is
 * it; the client never follows one, because a redirect could send a pinned, SSRF-checked
 * request (and its credential) somewhere else. It is also thrown when an injected client
 * followed a redirect anyway — a host wrapper dropping `max_redirects: 0` — and then
 * $status is what the redirect target answered and $location is null: the 3xx that carried
 * a Location was consumed by the client, and the response reaching HttpExchange is the
 * target's own. The message is the same in both cases, and states the client's
 * own rule rather than what the injected transport did. The Location is kept on the
 * exception for the host to log, not put in the message.
 */
final class McpRedirectException extends McpTransportException
{
    public function __construct(string $method, public readonly int $status, public readonly ?string $location)
    {
        parent::__construct(sprintf('MCP %s returned HTTP %d; redirects are not followed.', $method, $status));
    }
}
