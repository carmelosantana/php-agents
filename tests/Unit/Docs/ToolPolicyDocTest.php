<?php

declare(strict_types=1);

// docs/TOOLS-AND-TOOLKITS.md once documented shouldExecute(ToolInterface, ToolCall): bool, long
// after the interface became shouldExecute(string, array): true|string. This pins the doc to the
// interface so the two cannot drift apart silently again.

test('the policy example uses the real shouldExecute signature', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/TOOLS-AND-TOOLKITS.md');
    $method = new ReflectionMethod(\CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface::class, 'shouldExecute');

    expect((string) $method->getReturnType())->toBe('string|true')
        ->and($doc)->toContain('public function shouldExecute(string $toolName, array $arguments): true|string')
        ->and($doc)->not->toContain('shouldExecute(ToolInterface $tool, ToolCall $toolCall)');
});

test('the MCP section documents the contract names', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/TOOLS-AND-TOOLKITS.md');

    foreach (['McpServer', 'McpClient', 'McpToolkit', 'McpSessionStore', 'McpToolDefinition', 'fingerprint()', 'SchemaTool', 'JsonSchemaRepair', 'McpException'] as $name) {
        expect($doc)->toContain($name);
    }
});
