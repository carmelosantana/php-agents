<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * The server answered with a JSON-RPC error object. The code is also the exception
 * code. The server's message is cut to 200 bytes, since it is text from outside.
 *
 * The formatting and the cut happen in this constructor, which leaves no later seam, so a
 * caller that must keep a secret out of the message redacts $rpcMessage before calling it —
 * McpClient::redact() does, for every McpServer::$headers value and every session id that
 * client has held (spec §2, amendment 3, 2026-09-21). $data is kept as the server sent it and is not part of the
 * message; a host that logs it logs untrusted, unredacted server data.
 */
final class McpRpcException extends McpProtocolException
{
    public function __construct(
        string $method,
        public readonly int $rpcCode,
        string $rpcMessage,
        public readonly mixed $data = null,
        public readonly int $httpStatus = 200,
    ) {
        parent::__construct(
            sprintf('MCP %s failed with JSON-RPC error %d: %s', $method, $rpcCode, mb_strcut($rpcMessage, 0, 200, 'UTF-8')),
            $rpcCode,
        );
    }
}
