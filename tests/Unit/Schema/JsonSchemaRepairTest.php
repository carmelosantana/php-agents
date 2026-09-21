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

test('a schema with no keywords at all cannot be repaired and comes back as a list', function () {
    // repair() only ever fires on a keyword it finds, and its signature returns an array,
    // so the root `{}` an MCP tool that takes no input publishes has nothing to fire on.
    // Establishing a root `type` is the caller's job — SchemaTool::toFunctionSchema() does it.
    expect(encodedRepair('{}'))->toBe('[]')
        ->and(encodedRepair('{"type":"object"}'))->toBe('{"type":"object"}');
});

test('contentSchema is repaired', function () {
    $json = '{"type":"string","contentMediaType":"application/json","contentSchema":{}}';
    expect(encodedRepair($json))->toBe($json);
});

test('every map-valued keyword is repaired', function (string $keyword) {
    $nested = '{"' . $keyword . '":{"a":{"properties":{}}}}';
    expect(encodedRepair('{"' . $keyword . '":{}}'))->toBe('{"' . $keyword . '":{}}')
        ->and(encodedRepair($nested))->toBe($nested);
})->with(['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas']);

test('every schema-valued keyword is repaired', function (string $keyword) {
    $nested = '{"' . $keyword . '":{"properties":{}}}';
    expect(encodedRepair('{"' . $keyword . '":{}}'))->toBe('{"' . $keyword . '":{}}')
        ->and(encodedRepair($nested))->toBe($nested);
})->with([
    'additionalProperties', 'unevaluatedProperties', 'items', 'additionalItems', 'unevaluatedItems',
    'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'contentSchema',
]);

test('every schema-list keyword is recursed into and stays a list', function (string $keyword) {
    $json = '{"' . $keyword . '":[{"properties":{}},{"type":"null"}]}';
    expect(encodedRepair($json))->toBe($json);
})->with(['anyOf', 'oneOf', 'allOf', 'prefixItems']);

test('a keyword in no table is left exactly as decoded', function () {
    // The negative control for the three tests above: repair() discriminates by keyword
    // rather than objectifying every empty array. `default` and `examples` losing their
    // `{}` is the known, accepted cost of an assoc decode; `dependencies` is left alone
    // because its values mix schemas and string lists.
    expect(encodedRepair('{"default":{},"examples":[{}],"dependencies":{"a":{}},"x-vendor":{}}'))
        ->toBe('{"default":[],"examples":[[]],"dependencies":{"a":[]},"x-vendor":[]}');
});
