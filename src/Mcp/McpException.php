<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * Base of every error the MCP client throws. It extends RuntimeException so a host
 * can catch one type for "this server is unavailable right now". No message in this
 * tree contains a configured header value or a session id.
 */
class McpException extends \RuntimeException {}
