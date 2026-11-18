<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Internal\ResultMapper;
use CarmeloSantana\PHPAgents\Mcp\McpClient;
use CarmeloSantana\PHPAgents\Mcp\McpServer;
use Tests\Support\Mcp\FakeMcpServer;

// An MCP server's tools/call result is untrusted text that reaches the model (content) and the
// host (metadata). A server that echoes the configured Authorization header, the session id or
// a credential-shaped string gets it redacted in both.

function redactionMcpClient(FakeMcpServer $fake): McpClient
{
    return new McpClient(new McpServer(
        url: 'https://mcp.example.test/mcp',
        headers: ['Authorization' => 'Bearer secret-token-123'],
        protocolVersion: McpServer::PROTOCOL_2025,
    ), $fake->client());
}

/** @return list<string> every string key and value in $value, recursively */
function redactionStrings(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $k => $v) {
        if (is_string($k)) {
            $out[] = $k;
        }
        array_push($out, ...redactionStrings($v));
    }

    return $out;
}

test('an MCP server echoing the Authorization header gets it redacted in content and metadata', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = [FakeMcpServer::tool('echo')];
    $fake->results['echo'] = static fn(array $a) => [
        'content' => [['type' => 'text', 'text' => 'you sent Authorization: Bearer secret-token-123 and session sess-1']],
        'structuredContent' => ['headers' => ['authorization' => 'Bearer secret-token-123']],
    ];

    $result = redactionMcpClient($fake)->callTool('echo', []);

    expect($result->content)->toContain('[redacted]')
        ->and($result->content)->not->toContain('secret-token-123')
        ->and($result->content)->not->toContain('sess-1')
        ->and($result->metadata['mcp']['content'][0]['text'])->toContain('[redacted]')
        ->and($result->metadata['mcp']['content'][0]['text'])->not->toContain('secret-token-123');
    foreach (redactionStrings($result->metadata) as $s) {
        expect($s)->not->toContain('secret-token-123');
    }
});

test('a credential-shaped string an MCP server returns is redacted even when not configured', function () {
    $fake = new FakeMcpServer(FakeMcpServer::LEGACY);
    $fake->tools = [FakeMcpServer::tool('env')];
    $fake->results['env'] = static fn(array $a) => [
        'isError' => true,
        'content' => [['type' => 'text', 'text' => 'OPENAI key sk-ant-' . str_repeat('Q', 30)]],
        'structuredContent' => ['ghp_' . str_repeat('K', 30) => 'key-as-name'],
    ];

    $result = redactionMcpClient($fake)->callTool('env', []);

    expect($result->content)->toBe('OPENAI key [redacted]');
    foreach (redactionStrings($result->metadata) as $s) {
        expect($s)->not->toContain('QQQQ')->and($s)->not->toContain('ghp_');
    }
});

test('ResultMapper redacts before truncation, so a credential straddling the cut is not half-shown', function () {
    // Raw, the key spans bytes 21-68, so a cut at 35 would show its first 14 bytes.
    $text = str_repeat('a', 20) . ' sk-ant-' . str_repeat('Z', 40) . ' ' . str_repeat('b', 100);
    $result = ResultMapper::toToolResult(
        ['content' => [['type' => 'text', 'text' => $text]]],
        35,
        static fn(string $s): string => \CarmeloSantana\PHPAgents\Security\UntrustedText::redactSecrets($s),
    );

    expect($result->content)->not->toContain('sk-ant')
        ->and($result->content)->not->toContain('ZZ')
        ->and($result->content)->toStartWith(str_repeat('a', 20) . ' [redacted]')
        ->and($result->metadata['mcp']['truncated'])->toBeTrue();
});

test('ResultMapper applies the redactor to metadata keys and values recursively', function () {
    $result = ResultMapper::toToolResult(
        ['content' => [['type' => 'text', 'text' => 'X']], 'structuredContent' => ['X' => ['deep' => ['X', 1, null]]]],
        1000,
        static fn(string $s): string => str_replace('X', 'Y', $s),
    );

    expect($result->content)->toBe('Y')
        ->and($result->metadata['mcp']['content'])->toBe([['type' => 'text', 'text' => 'Y']])
        ->and($result->metadata['mcp']['structuredContent'])->toBe(['Y' => ['deep' => ['Y', 1, null]]]);
});

test('ResultMapper without a redactor leaves text as sent', function () {
    $result = ResultMapper::toToolResult(['content' => [['type' => 'text', 'text' => 'sk-ant-' . str_repeat('Z', 40)]]], 1000);

    expect($result->content)->toBe('sk-ant-' . str_repeat('Z', 40));
});
