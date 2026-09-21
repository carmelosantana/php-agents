<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * What McpToolkit needs from an MCP client. McpClient is the Streamable HTTP
 * implementation; a host with another transport can implement this itself.
 * Both methods throw McpException subclasses.
 */
interface McpClientInterface
{
    /**
     * Every tool the server lists, across all pages.
     *
     * @return list<McpToolDefinition>
     */
    public function listTools(): array;

    /**
     * Call a tool by the name the server knows it by. A result the server marks
     * `isError` comes back as an error ToolResult, not an exception.
     *
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments): ToolResult;
}
