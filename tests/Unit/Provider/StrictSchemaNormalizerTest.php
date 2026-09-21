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

test('qualifies rejects free-form objects, refs and pattern properties', function () {
    expect(StrictSchemaNormalizer::qualifies(['type' => 'object']))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies(['type' => 'object', 'properties' => ['a' => ['type' => 'object']]]))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies(['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/x']]]))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies(['type' => 'object', 'patternProperties' => ['^x' => ['type' => 'string']]]))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies(['type' => 'object', 'properties' => ['a' => ['type' => ['object', 'null'], 'additionalProperties' => true]]]))->toBeFalse();
});

test('qualifies accepts a closed empty object and ordinary nested objects', function () {
    expect(StrictSchemaNormalizer::qualifies(['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false]))->toBeTrue()
        ->and(StrictSchemaNormalizer::qualifies(['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]]]))->toBeTrue();
});

test('containsOpenObject finds an open map under a combinator inside items', function () {
    $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['anyOf' => [['type' => 'object', 'additionalProperties' => true]]]]]];

    expect(StrictSchemaNormalizer::containsOpenObject($schema))->toBeTrue();
});

test('normalize closes objects under items, combinators and defs', function () {
    $out = StrictSchemaNormalizer::normalize([
        'type' => 'object',
        'properties' => [
            'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]],
            'either' => ['oneOf' => [['type' => 'object', 'properties' => ['x' => ['type' => 'string']]], ['type' => 'string']]],
        ],
        'required' => ['rows', 'either'],
        '$defs' => ['thing' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]],
    ]);

    expect($out['properties']['rows']['items']['additionalProperties'])->toBeFalse()
        ->and($out['properties']['rows']['items']['required'])->toBe(['id'])
        ->and($out['properties']['either']['oneOf'][0]['additionalProperties'])->toBeFalse()
        ->and($out['$defs']['thing']['additionalProperties'])->toBeFalse();
});

test('normalize closes an optional nested object before wrapping it as nullable', function () {
    $out = StrictSchemaNormalizer::normalize([
        'type' => 'object',
        'properties' => ['meta' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]],
    ]);

    expect($out['properties']['meta']['anyOf'][0]['additionalProperties'])->toBeFalse()
        ->and($out['properties']['meta']['anyOf'][0]['required'])->toBe(['a'])
        ->and($out['properties']['meta']['anyOf'][1])->toBe(['type' => 'null']);
});

test('normalize closes a nullable object given as a type array', function () {
    $out = StrictSchemaNormalizer::normalize([
        'type' => 'object',
        'properties' => ['meta' => ['type' => ['object', 'null'], 'properties' => ['a' => ['type' => 'string']]]],
        'required' => ['meta'],
    ]);

    expect($out['properties']['meta']['additionalProperties'])->toBeFalse();
});
