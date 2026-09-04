<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\StrictSchemaNormalizer;

test('containsOpenObject is false for a fully closed schema', function () {
    $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']];

    expect(StrictSchemaNormalizer::containsOpenObject($schema))->toBeFalse();
});

test('containsOpenObject detects an open map at any depth', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'data' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ],
    ];

    expect(StrictSchemaNormalizer::containsOpenObject($schema))->toBeTrue();
});

test('normalize closes the object and requires every property', function () {
    $out = StrictSchemaNormalizer::normalize([
        'type' => 'object',
        'properties' => ['summary' => ['type' => 'string'], 'note' => ['type' => 'string']],
        'required' => ['summary'],
    ]);

    $required = $out['required'];
    sort($required);
    expect($out['additionalProperties'])->toBeFalse()
        ->and($required)->toBe(['note', 'summary']);
});

test('normalize types an optional property as nullable', function () {
    $out = StrictSchemaNormalizer::normalize([
        'type' => 'object',
        'properties' => ['summary' => ['type' => 'string'], 'note' => ['type' => 'string']],
        'required' => ['summary'],
    ]);

    expect($out['properties']['note'])->toBe(['anyOf' => [['type' => 'string'], ['type' => 'null']]])
        ->and($out['properties']['summary'])->toBe(['type' => 'string']);
});

test('normalize recurses into nested object properties', function () {
    $out = StrictSchemaNormalizer::normalize([
        'type' => 'object',
        'properties' => [
            'meta' => ['type' => 'object', 'properties' => ['author' => ['type' => 'string']]],
        ],
        'required' => ['meta'],
    ]);

    expect($out['properties']['meta']['additionalProperties'])->toBeFalse()
        ->and($out['properties']['meta']['required'])->toBe(['author']);
});
