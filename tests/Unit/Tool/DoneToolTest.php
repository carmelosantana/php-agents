<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\DoneTool;

test('DoneTool::NAME constant is "done"', function () {
    expect(DoneTool::NAME)->toBe('done');
});

test('DoneTool::create returns a ToolInterface with correct name', function () {
    $tool = DoneTool::create();

    expect($tool->name())->toBe('done');
});

test('DoneTool::create schema matches expected structure', function () {
    $tool = DoneTool::create();
    $schema = $tool->toFunctionSchema();

    expect($schema)->toHaveKey('type', 'function');
    expect($schema['function']['name'])->toBe('done');
    expect($schema['function']['parameters']['type'])->toBe('object');
    expect($schema['function']['parameters']['properties'])->toHaveKey('response');
    expect($schema['function']['parameters']['properties']['response']['type'])->toBe('string');
    expect($schema['function']['parameters']['required'])->toBe(['response']);
});

test('DoneTool::create execute returns success with response content', function () {
    $tool = DoneTool::create();
    $result = $tool->execute(['response' => 'Hello world']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toBe('Hello world');
});

test('DoneTool::create execute handles missing response with error', function () {
    $tool = DoneTool::create();
    $result = $tool->execute([]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Missing required parameters');
});
