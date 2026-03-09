<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\SchemaUtils;

// --- stripKeywords ---

test('stripKeywords removes specified keywords', function () {
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        '$ref' => '#/defs/Foo',
        'description' => 'Keep me',
    ];

    $result = SchemaUtils::stripKeywords($schema, ['additionalProperties', '$ref']);

    expect($result)->toBe([
        'type' => 'object',
        'description' => 'Keep me',
    ]);
});

test('stripKeywords handles empty keyword list', function () {
    $schema = ['type' => 'string', 'format' => 'email'];

    $result = SchemaUtils::stripKeywords($schema, []);

    expect($result)->toBe($schema);
});

test('stripKeywords ignores missing keywords', function () {
    $schema = ['type' => 'integer'];

    $result = SchemaUtils::stripKeywords($schema, ['nonexistent', 'also_missing']);

    expect($result)->toBe(['type' => 'integer']);
});

// --- flattenCombinator ---

test('flattenCombinator picks first non-null variant', function () {
    $schema = [
        'description' => 'A value',
        'anyOf' => [
            ['type' => 'null'],
            ['type' => 'string', 'minLength' => 1],
            ['type' => 'integer'],
        ],
    ];

    $result = SchemaUtils::flattenCombinator($schema, 'anyOf');

    expect($result['type'])->toBe('string')
        ->and($result['minLength'])->toBe(1)
        ->and($result['description'])->toBe('A value')
        ->and($result)->not->toHaveKey('anyOf');
});

test('flattenCombinator falls back to string when all null', function () {
    $schema = [
        'anyOf' => [
            ['type' => 'null'],
            ['type' => 'null'],
        ],
    ];

    $result = SchemaUtils::flattenCombinator($schema, 'anyOf');

    expect($result['type'])->toBe('string')
        ->and($result)->not->toHaveKey('anyOf');
});

test('flattenCombinator skips non-array variants', function () {
    $schema = [
        'oneOf' => [
            'not-an-array',
            ['type' => 'null'],
            ['type' => 'boolean'],
        ],
    ];

    $result = SchemaUtils::flattenCombinator($schema, 'oneOf');

    expect($result['type'])->toBe('boolean');
});

test('flattenCombinator preserves parent keys', function () {
    $schema = [
        'description' => 'Parent description',
        'default' => 'hello',
        'allOf' => [
            ['type' => 'string', 'maxLength' => 100],
        ],
    ];

    $result = SchemaUtils::flattenCombinator($schema, 'allOf');

    expect($result['description'])->toBe('Parent description')
        ->and($result['default'])->toBe('hello')
        ->and($result['type'])->toBe('string')
        ->and($result['maxLength'])->toBe(100);
});

// --- demoteConstraints ---

test('demoteConstraints appends hints to description', function () {
    $schema = [
        'type' => 'integer',
        'description' => 'A number',
        'minimum' => 0,
        'maximum' => 100,
    ];

    $templates = [
        'minimum' => 'Min: %s.',
        'maximum' => 'Max: %s.',
    ];

    $result = SchemaUtils::demoteConstraints($schema, $templates);

    expect($result['description'])->toBe('A number Min: 0. Max: 100.');
});

test('demoteConstraints creates description when missing', function () {
    $schema = [
        'type' => 'string',
        'minLength' => 3,
    ];

    $templates = ['minLength' => 'Minimum length: %s.'];

    $result = SchemaUtils::demoteConstraints($schema, $templates);

    expect($result['description'])->toBe('Minimum length: 3.');
});

test('demoteConstraints skips missing keywords', function () {
    $schema = ['type' => 'string'];

    $templates = [
        'minimum' => 'Min: %s.',
        'pattern' => 'Pattern: %s.',
    ];

    $result = SchemaUtils::demoteConstraints($schema, $templates);

    expect($result)->not->toHaveKey('description');
});

test('demoteConstraints handles non-scalar values', function () {
    $schema = [
        'type' => 'array',
        'default' => ['a', 'b'],
    ];

    $templates = ['default' => 'Default: %s.'];

    $result = SchemaUtils::demoteConstraints($schema, $templates);

    expect($result['description'])->toBe('Default: ["a","b"].');
});

test('demoteConstraints with empty description field', function () {
    $schema = [
        'type' => 'number',
        'description' => '',
        'minimum' => 5,
    ];

    $templates = ['minimum' => 'Minimum value: %s.'];

    $result = SchemaUtils::demoteConstraints($schema, $templates);

    expect($result['description'])->toBe('Minimum value: 5.');
});
