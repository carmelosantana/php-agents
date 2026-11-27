<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

/**
 * An error a tool handler reports to the client on purpose.
 *
 * The message, and the optional code and data, are sent to the client as an
 * `isError` tool result. Only put text here that the caller may see; a
 * previous exception is never sent.
 */
final class ToolError extends \RuntimeException
{
    /**
     * @param int|null $errorCode a machine-readable code, such as a JSON-RPC error code
     */
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly mixed $data = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
