<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Prompt\SystemPrompt;
use CarmeloSantana\PHPAgents\Tool\SchemaTool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('the function schema carries the raw schema, repaired', function () {
    $schema = json_decode('{"type":"object","properties":{"q":{"type":"string"},"opts":{"type":"object","properties":{}}},"required":["q"]}', true);
    $tool = new SchemaTool('search', 'Search.', $schema, fn(array $a): ToolResult => ToolResult::success('x'));

    expect(json_encode($tool->toFunctionSchema(), JSON_UNESCAPED_SLASHES))->toBe(
        '{"type":"function","function":{"name":"search","description":"Search.","parameters":{"type":"object","properties":{"q":{"type":"string"},"opts":{"type":"object","properties":{}}},"required":["q"]}}}',
    );
});

test('a schema with no type is given type object', function () {
    $tool = new SchemaTool('t', 'd', [], fn(array $a): ToolResult => ToolResult::success('x'));

    expect($tool->toFunctionSchema()['function']['parameters'])->toBe(['type' => 'object']);
});

test('parameters() is empty and the system prompt renders no parameters block', function () {
    $tool = new SchemaTool('search', 'Search the tracker.', ['type' => 'object'], fn(array $a): ToolResult => ToolResult::success('x'));
    $prompt = SystemPrompt::render(SystemPrompt::withTools([$tool], SystemPrompt::withIdentity('Identity')));

    expect($tool->parameters())->toBe([])
        ->and($prompt)->toContain('search')
        ->and($prompt)->toContain('Search the tracker.')
        ->and($prompt)->not->toContain('Parameters:');
});

test('execute passes arguments through without validating them', function () {
    $seen = null;
    $tool = new SchemaTool('t', 'd', ['type' => 'object', 'required' => ['q']], function (array $a) use (&$seen): ToolResult {
        $seen = $a;
        return ToolResult::success('ok');
    });

    expect($tool->execute(['other' => 1])->content)->toBe('ok')
        ->and($seen)->toBe(['other' => 1]);
});

test('a throw becomes a fixed error that does not quote the exception', function () {
    $tool = new SchemaTool('remote__search', 'd', ['type' => 'object'], function (array $a): ToolResult {
        throw new RuntimeException('POST https://secret.example/mcp failed');
    });
    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('The remote__search tool failed before it could answer.');
});

test('a closure that returns something other than a ToolResult is an error, not a fatal', function () {
    $tool = new SchemaTool('t', 'd', ['type' => 'object'], fn(array $a) => 'not a result');

    expect($tool->execute([])->status)->toBe(ToolResultStatus::Error);
});
