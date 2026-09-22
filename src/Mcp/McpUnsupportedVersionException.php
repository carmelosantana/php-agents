<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/** The server and this client share no protocol version (this client speaks 2026-07-28 and 2025-11-25). */
final class McpUnsupportedVersionException extends McpProtocolException {}
