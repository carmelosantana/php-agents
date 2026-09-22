<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/** The request did not produce a usable HTTP exchange: network failure, timeout, size cap, or an unexpected status. */
class McpTransportException extends McpException {}
