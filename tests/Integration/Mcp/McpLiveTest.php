<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Mcp\McpClient;
use CarmeloSantana\PHPAgents\Mcp\McpServer;
use Symfony\Component\HttpClient\HttpClient;

// Opt-in: talks to a real MCP server. CI sets none of these variables —
// `grep -rn 'PHP_AGENTS_MCP' .github/workflows/` prints nothing.
//   PHP_AGENTS_MCP_URL            the Streamable HTTP endpoint
//   PHP_AGENTS_MCP_HEADER         optional, "Name: value" (e.g. "Authorization: Basic …")
//   PHP_AGENTS_MCP_READONLY_TOOL  optional, a tool that is safe to call with {}
//
// The two skips are written differently because they sit in different places. A plain
// function is not bound to the TestCase, so it reaches the current test through Pest's
// test() helper; a test closure is bound, so $this is the TestCase. Both were run with
// neither variable set and both reported as skipped with their message.

function liveMcpServer(): McpServer
{
    $url = getenv('PHP_AGENTS_MCP_URL');
    if (!is_string($url) || $url === '') {
        test()->markTestSkipped('Set PHP_AGENTS_MCP_URL (and optionally PHP_AGENTS_MCP_HEADER, PHP_AGENTS_MCP_READONLY_TOOL) to run the live MCP test.');
    }
    $headers = [];
    $header = getenv('PHP_AGENTS_MCP_HEADER');
    if (is_string($header) && str_contains($header, ':')) {
        [$name, $value] = array_map('trim', explode(':', $header, 2));
        $headers[$name] = $value;
    }

    return new McpServer($url, $headers);
}

test('a real server lists tools with stable fingerprints', function () {
    $server = liveMcpServer();
    $first = (new McpClient($server, HttpClient::create()))->listTools();
    $second = (new McpClient($server, HttpClient::create()))->listTools();

    expect($first)->not->toBeEmpty()
        ->and(array_map(static fn($t) => $t->fingerprint(), $first))->toBe(array_map(static fn($t) => $t->fingerprint(), $second));
});

// ToolResultStatus has a third case, Timeout, which this assertion leaves out.
// `grep -rn 'ToolResultStatus::Timeout' src/` returns the enum's own case declaration and
// nothing that constructs one, and a tools/call that times out throws McpTransportException
// rather than answering, so the MCP path cannot reach this assertion with a Timeout.
test('a real read-only tool call returns a result', function () {
    $tool = getenv('PHP_AGENTS_MCP_READONLY_TOOL');
    if (!is_string($tool) || $tool === '') {
        $this->markTestSkipped('Set PHP_AGENTS_MCP_READONLY_TOOL to call a tool on the live MCP server.');
    }
    $result = (new McpClient(liveMcpServer(), HttpClient::create()))->callTool($tool, []);

    expect($result->status)->toBeIn([ToolResultStatus::Success, ToolResultStatus::Error])
        ->and($result->metadata)->toHaveKey('mcp');
});
