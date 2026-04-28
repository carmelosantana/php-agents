<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\MapParameter;
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

test('tool execute validates parameter constraints before running callback', function () {
    $callbackRan = false;

    $tool = new Tool(
        name: 'create_project',
        description: 'Create a project',
        parameters: [
            new StringParameter('slug', 'Project slug', pattern: '/^[a-z-]+$/'),
            new NumberParameter('count', 'Project count', required: false, integer: true, minimum: 1),
        ],
        callback: function (array $input) use (&$callbackRan) {
            $callbackRan = true;

            return sprintf('%s:%d', $input['slug'], $input['count']);
        },
    );

    $result = $tool->execute(['slug' => 'Invalid Slug', 'count' => 0]);

    expect($callbackRan)->toBeFalse();
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Parameter validation failed');
    expect($result->content)->toContain('required pattern');
    expect($result->content)->toContain('at least 1');
});

test('tool execute passes normalized values from validation to callback', function () {
    $tool = new Tool(
        name: 'count_items',
        description: 'Count items',
        parameters: [
            new NumberParameter('count', 'Item count', integer: true, minimum: 1),
        ],
        callback: fn(array $input) => gettype($input['count']) . ':' . $input['count'],
    );

    $result = $tool->execute(['count' => '2']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('integer:2');
});

test('tool execute passes normalized structured values from JSON strings to callback', function () {
    $tool = new Tool(
        name: 'request',
        description: 'Issue a structured request',
        parameters: [
            new MapParameter('headers', 'Headers to send', required: false),
        ],
        callback: fn(array $input) => json_encode($input['headers'], JSON_THROW_ON_ERROR),
    );

    $result = $tool->execute(['headers' => '{"Accept":"application/json"}']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(json_decode($result->content, true))->toBe(['Accept' => 'application/json']);
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

test('toFunctionSchema preserves open-ended map parameters', function () {
    $tool = new Tool(
        name: 'request',
        description: 'HTTP request',
        parameters: [
            new MapParameter('headers', 'Headers to send', required: false),
        ],
        callback: fn(array $input) => '',
    );

    $schema = $tool->toFunctionSchema();

    expect($schema['function']['parameters']['properties']['headers'])->toBe([
        'type' => 'object',
        'description' => 'Headers to send',
        'additionalProperties' => true,
    ]);
});
