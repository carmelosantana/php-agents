<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

test('tool returns name and description', function () {
    $tool = new Tool(
        name: 'test_tool',
        description: 'A test tool',
        parameters: [],
        callback: fn(array $input) => 'result',
    );

    expect($tool->name())->toBe('test_tool');
    expect($tool->description())->toBe('A test tool');
});

test('tool execute returns ToolResult on success', function () {
    $tool = new Tool(
        name: 'echo',
        description: 'Echo input',
        parameters: [new StringParameter('text', 'Text to echo')],
        callback: fn(array $input) => $input['text'] ?? '',
    );

    $result = $tool->execute(['text' => 'hello']);

    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('hello');
});

test('tool execute catches exceptions and returns error', function () {
    $tool = new Tool(
        name: 'fail',
        description: 'Always fails',
        parameters: [],
        callback: fn(array $input) => throw new \RuntimeException('boom'),
    );

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('boom');
});

test('tool wraps ToolResult from callback', function () {
    $tool = new Tool(
        name: 'custom',
        description: 'Returns custom result',
        parameters: [],
        callback: fn(array $input) => ToolResult::success('custom output'),
    );

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('custom output');
});

test('toFunctionSchema generates correct structure', function () {
    $tool = new Tool(
        name: 'search',
        description: 'Search for items',
        parameters: [
            new StringParameter('query', 'Search query'),
        ],
        callback: fn(array $input) => 'result',
    );

    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('search');
    expect($schema['function']['description'])->toBe('Search for items');
    expect($schema['function']['parameters']['type'])->toBe('object');
    expect($schema['function']['parameters']['properties'])->toHaveKey('query');
    expect($schema['function']['parameters']['required'])->toBe(['query']);
});

test('toFunctionSchema omits required when no required parameters', function () {
    $tool = new Tool(
        name: 'test',
        description: 'test',
        parameters: [],
        callback: fn(array $input) => '',
    );

    $schema = $tool->toFunctionSchema();
    expect($schema['function']['parameters'])->not->toHaveKey('required');
});
