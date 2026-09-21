<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Schema\JsonSchemaRepair;

// json_decode($json, true) turns every `{}` into `[]`, and json_encode([]) writes `[]` back.
// A provider handed `"properties": []` rejects the tool. repair() puts the object back
// only where JSON Schema requires an object, and leaves list-valued keywords alone.

function encodedRepair(string $json): string
{
    return json_encode(JsonSchemaRepair::repair(json_decode($json, true)), JSON_UNESCAPED_SLASHES);
}

test('an empty properties map encodes as an object again', function () {
    expect(encodedRepair('{"type":"object","properties":{}}'))->toBe('{"type":"object","properties":{}}');
});

test('empty schema-valued keywords encode as objects at every depth', function () {
    $json = '{"type":"object","properties":{"a":{"type":"array","items":{}},"b":{"type":"object","additionalProperties":{}},"c":{"not":{}}},"$defs":{"x":{"type":"object","properties":{}}}}';
    expect(encodedRepair($json))->toBe($json);
});

test('combinator branches are repaired', function () {
    $json = '{"anyOf":[{"type":"object","properties":{}},{"type":"null"}]}';
    expect(encodedRepair($json))->toBe($json);
});

test('list-valued and value keywords are left alone', function () {
    $json = '{"type":"object","properties":{"e":{"enum":[]},"d":{"default":[]},"k":{"const":[]},"x":{"examples":[]}},"required":[]}';
    expect(encodedRepair($json))->toBe($json);
});

test('a map whose keys are numeric strings stays an object', function () {
    expect(encodedRepair('{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"string"}}}'))
        ->toBe('{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"string"}}}');
});

test('the draft-04 tuple form of items stays a list', function () {
    $json = '{"type":"array","items":[{"type":"string"},{"type":"object","properties":{}}]}';
    expect(encodedRepair($json))->toBe($json);
});

test('a schema with nothing to repair comes back unchanged', function () {
    $schema = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']];
    expect(JsonSchemaRepair::repair($schema))->toBe($schema);
});
