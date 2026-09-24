<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * One remote MCP server as McpClient reaches it: the Streamable HTTP endpoint, the
 * static headers sent on every request (typically `Authorization`), and the limits
 * the client enforces.
 *
 * - $timeout: seconds, used as both the idle timeout and the whole-request cap.
 * - $maxResponseBytes: caps an HTTP response body.
 * - $maxResultBytes: caps the text a tool result hands the model.
 * - $protocolVersion: null lets the client detect the protocol version; one of
 *   the two PROTOCOL_* constants pins it.
 *
 * The URL is not validated here. A host that builds this from stored settings
 * gets a transport error (McpTransportException, a RuntimeException) from a bad
 * URL rather than a LogicException at construction. Header values are secrets:
 * nothing in this namespace puts one in an exception message, and sessionKey()
 * hashes them.
 */
final readonly class McpServer
{
    public const PROTOCOL_2026 = '2026-07-28';
    public const PROTOCOL_2025 = '2025-11-25';

    /**
     * @param array<string, string> $headers header name => value
     */
    public function __construct(
        public string $url,
        public array $headers = [],
        public float $timeout = 30.0,
        public int $maxResponseBytes = 1_048_576,
        public ?string $protocolVersion = null,
        public int $maxResultBytes = 65_536,
    ) {
        if ($protocolVersion !== null && $protocolVersion !== self::PROTOCOL_2026 && $protocolVersion !== self::PROTOCOL_2025) {
            throw new \InvalidArgumentException(sprintf(
                'MCP protocol version must be null, "%s" or "%s".',
                self::PROTOCOL_2026,
                self::PROTOCOL_2025,
            ));
        }
        if ($timeout <= 0 || $maxResponseBytes < 1 || $maxResultBytes < 1) {
            throw new \InvalidArgumentException('MCP timeout and byte limits must be positive.');
        }
    }

    /**
     * The key McpClient uses with an McpSessionStore: a sha256 of the URL and the
     * headers (names sorted), so a store never holds a credential and a changed
     * credential starts a fresh session.
     */
    public function sessionKey(): string
    {
        $headers = $this->headers;
        ksort($headers, SORT_STRING);

        return hash('sha256', $this->url . "\n" . json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
