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

test('qualifies rejects an object under a conditional branch', function () {
    // normalize() never rewrites `not`/`if`/`then`/`else`, so an object there would
    // go out unclosed under strict:true and OpenAI rejects the request outright.
    $conditional = [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'string']],
        'required' => ['a'],
        'if' => ['required' => ['a']],
        'then' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
    ];
    $negated = [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'string']],
        'required' => ['a'],
        'not' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
    ];

    expect(StrictSchemaNormalizer::qualifies($conditional))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies($negated))->toBeFalse();
});

test('qualifies rejects even an already closed object under then', function () {
    // The rule is uniform: any object under a conditional branch disqualifies the
    // schema. Admitting an already-closed one would need a second predicate that
    // proves a whole subtree strict-clean at every depth, which nothing else needs.
    $schema = [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'string']],
        'required' => ['a'],
        'additionalProperties' => false,
        'then' => [
            'type' => 'object',
            'properties' => ['b' => ['type' => 'string']],
            'required' => ['b'],
            'additionalProperties' => false,
        ],
    ];

    expect(StrictSchemaNormalizer::qualifies($schema))->toBeFalse();
});

test('qualifies rejects a typeless properties map under a conditional branch', function () {
    // normalize() treats a node with `properties` and no `type` as an object, so
    // qualifies() must too, or the same unclosed-object request gets sent.
    $schema = [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'string']],
        'required' => ['a'],
        'then' => ['properties' => ['b' => ['type' => 'string']]],
    ];

    expect(StrictSchemaNormalizer::qualifies($schema))->toBeFalse();
});

test('qualifies rejects a typeless node that is open or free-form', function () {
    // JsonSchemaRepair defaults only the ROOT `type`, so a server-supplied MCP schema
    // can carry nested nodes with `properties` and no `type`. normalize() rewrites
    // those as objects, so the predicates have to judge them as objects too — or an
    // explicit `additionalProperties: true` gets flipped to false under strict:true.
    $open = [
        'type' => 'object',
        'properties' => ['a' => ['properties' => ['b' => ['type' => 'string']], 'additionalProperties' => true]],
        'required' => ['a'],
    ];
    $freeForm = [
        'type' => 'object',
        'properties' => ['a' => ['properties' => new stdClass()]],
        'required' => ['a'],
    ];

    expect(StrictSchemaNormalizer::qualifies($open))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies($freeForm))->toBeFalse();
});

test('containsOpenObject finds an open map under contains', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'array', 'contains' => ['type' => 'object', 'additionalProperties' => true]]],
        'required' => ['a'],
    ];

    expect(StrictSchemaNormalizer::containsOpenObject($schema))->toBeTrue();
});

test('qualifies rejects an object under a position normalize does not rewrite', function () {
    // Same reasoning as the conditional branches: normalize() leaves these positions
    // alone, so an object there would ship unclosed under strict:true.
    $object = ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]];
    $wrap = fn(array $extra): array => [
        'type' => 'object',
        'properties' => ['a' => ['type' => 'array'] + $extra],
        'required' => ['a'],
    ];

    expect(StrictSchemaNormalizer::qualifies($wrap(['contains' => $object])))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies($wrap(['additionalItems' => $object])))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies($wrap(['unevaluatedProperties' => $object])))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies($wrap(['propertyNames' => $object])))->toBeFalse()
        ->and(StrictSchemaNormalizer::qualifies($wrap(['dependentSchemas' => ['x' => $object]])))->toBeFalse();
});

// ── invariant: every subschema position is judged or rewritten, never neither ──

/**
 * Paths of every object node in $node that OpenAI strict mode would reject: one
 * not closed with `additionalProperties: false`, or not listing every key of
 * `properties` in `required`.
 *
 * This walks every nested array generically and keeps no keyword list of its own,
 * so it cannot inherit a blind spot from the class under test.
 *
 * @return list<string>
 */
function strictUnclosedObjectPaths(mixed $node, string $path = '$'): array
{
    if (!is_array($node)) {
        return [];
    }

    $paths = [];
    $type = $node['type'] ?? null;
    $isObject = $type === 'object'
        || (is_array($type) && in_array('object', $type, true))
        || array_key_exists('properties', $node);

    if ($isObject) {
        $properties = $node['properties'] ?? [];
        $keys = is_array($properties) ? array_keys($properties) : [];
        $required = is_array($node['required'] ?? null) ? $node['required'] : [];

        if (($node['additionalProperties'] ?? null) !== false || array_diff($keys, $required) !== []) {
            $paths[] = $path;
        }
    }

    foreach ($node as $key => $child) {
        $paths = [...$paths, ...strictUnclosedObjectPaths($child, $path . '.' . $key)];
    }

    return $paths;
}

test('every subschema position is either disqualified or fully closed by normalize', function (array $schema) {
    // The contract this class owes its callers: a schema it accepts for strict mode
    // must come back with every object closed. A position walked but never rewritten,
    // or rewritten but never judged, breaks exactly this — which is how every bug in
    // this class so far has looked. The dataset is keyed by keyword, so a failure
    // names the offending one.
    $qualifies = StrictSchemaNormalizer::qualifies($schema);
    $unclosed = $qualifies ? strictUnclosedObjectPaths(StrictSchemaNormalizer::normalize($schema)) : [];

    expect($unclosed)->toBe([]);
})->with(function (): array {
    $unclosed = ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]];
    $root = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']];

    // Sourced from the JSON Schema 2020-12 vocabularies, NOT from this class's
    // constants: core (`$defs`); applicator (`prefixItems`, `items`, `contains`,
    // `additionalProperties`, `properties`, `patternProperties`, `dependentSchemas`,
    // `propertyNames`, `if`/`then`/`else`, `allOf`/`anyOf`/`oneOf`, `not`);
    // unevaluated (`unevaluatedItems`, `unevaluatedProperties`); content
    // (`contentSchema`). Plus the older spellings this codebase honours:
    // `definitions`, `additionalItems` and draft-07 `dependencies`.
    $single = ['items', 'additionalItems', 'contains', 'additionalProperties', 'propertyNames',
        'unevaluatedItems', 'unevaluatedProperties', 'contentSchema', 'if', 'then', 'else', 'not'];
    $list = ['prefixItems', 'allOf', 'anyOf', 'oneOf'];
    $map = ['$defs', 'definitions', 'patternProperties', 'dependentSchemas', 'dependencies'];

    $cases = ['properties' => [['type' => 'object', 'properties' => ['x' => $unclosed], 'required' => ['x']]]];

    // Not a keyword row: the other axis of the same position. `properties` is walked
    // unconditionally but only rewritten behind the object-node gate, so a parent with
    // an explicit non-object `type` is walked, never rewritten and never judged.
    $cases['properties on a non-object node'] = [[
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['a'],
        'properties' => ['a' => ['type' => 'string', 'properties' => ['x' => $unclosed]]],
    ]];
    foreach ($single as $keyword) {
        $cases[$keyword] = [$root + [$keyword => $unclosed]];
    }
    foreach ($list as $keyword) {
        $cases[$keyword] = [$root + [$keyword => [$unclosed]]];
    }
    foreach ($map as $keyword) {
        $cases[$keyword] = [$root + [$keyword => ['x' => $unclosed]]];
    }

    return $cases;
});

test('qualifies rejects properties on a node typed as something other than an object', function () {
    // children() walks `properties` unconditionally, but normalize() only rewrites it
    // behind the object-node gate. A `type: string` node carrying `properties` was
    // therefore walked, never rewritten and never judged, leaking unclosed objects
    // under strict:true. Disqualify rather than rewrite: writing
    // `additionalProperties: false` onto a string node would be meaningless.
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['a'],
        'properties' => [
            'a' => [
                'type' => 'string',
                'properties' => ['x' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]],
            ],
        ],
    ];

    expect(StrictSchemaNormalizer::qualifies($schema))->toBeFalse();
});

test('qualifies accepts a nullable object given as a type array', function () {
    // The boundary of the rule above: `type: ["object","null"]` IS an object node, and
    // spec section 4 lists nullable objects among the shapes normalize() closes, so
    // this must keep qualifying rather than be caught as a non-object bearing properties.
    $schema = [
        'type' => 'object',
        'properties' => ['meta' => ['type' => ['object', 'null'], 'properties' => ['a' => ['type' => 'string']]]],
        'required' => ['meta'],
    ];

    expect(StrictSchemaNormalizer::qualifies($schema))->toBeTrue()
        ->and(StrictSchemaNormalizer::normalize($schema)['properties']['meta']['additionalProperties'])->toBeFalse();
});
