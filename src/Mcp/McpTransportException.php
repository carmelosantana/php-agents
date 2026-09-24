<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * The request did not produce a usable HTTP exchange: network failure, timeout, size cap, an
 * unexpected status, or a header name or value holding CR, LF or NUL, refused before sending.
 */
class McpTransportException extends McpException {}
