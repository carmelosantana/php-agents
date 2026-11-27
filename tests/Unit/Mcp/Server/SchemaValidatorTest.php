<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Server\SchemaValidator;

// Mutation: coerce an integer-only value or ignore the string arm of the union.
it('accepts integer strings only with the string type', function (): void {
    expect(SchemaValidator::validate(['type' => 'integer'], 5))->toBeNull()
        ->and(SchemaValidator::validate(['type' => 'integer'], 5.5))->not->toBeNull()
        ->and(SchemaValidator::validate(['type' => 'integer'], '5'))->not->toBeNull()
        ->and(SchemaValidator::validate(['type' => ['integer', 'string']], '5'))->toBeNull()
        ->and(SchemaValidator::validate(['type' => ['integer', 'string']], true))->not->toBeNull();
});

// Mutation: skip the relevant nested keyword, treat null as missing, or count UTF-8 bytes.
it('validates nested object properties and list items with field paths', function (array $items, ?string $path): void {
    $error = SchemaValidator::validate([
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['score', 'title', 'status'],
                    'properties' => [
                        'score' => ['type' => 'number', 'minimum' => 1, 'maximum' => 5],
                        'title' => ['type' => ['string', 'null'], 'maxLength' => 2],
                        'status' => ['enum' => ['open', 'closed']],
                    ],
                ],
            ],
        ],
    ], ['items' => $items]);

    if ($path === null) {
        expect($error)->toBeNull();
    } else {
        expect($error)->toStartWith($path . ' ');
    }
})->with([
    'valid inclusive bounds and Unicode title' => [[
        ['score' => 1, 'title' => 'é界', 'status' => 'open'],
        ['score' => 5, 'title' => null, 'status' => 'closed'],
    ], null],
    'minimum' => [[['score' => 0, 'title' => 'a', 'status' => 'open']], 'items[0].score'],
    'maximum' => [[['score' => 6, 'title' => 'a', 'status' => 'open']], 'items[0].score'],
    'maxLength' => [[['score' => 2.5, 'title' => 'é界a', 'status' => 'open']], 'items[0].title'],
    'enum' => [[['score' => 3, 'title' => 'a', 'status' => 'other']], 'items[0].status'],
    'required' => [[['score' => 3, 'title' => 'a']], 'items[0].status'],
    'items' => [['a'], 'items[0]'],
]);

// Mutation: treat JSON objects and lists as interchangeable or compare enum values loosely.
it('distinguishes JSON containers and enum types', function (): void {
    expect(SchemaValidator::validate(['type' => 'object'], new stdClass()))->toBeNull()
        ->and(SchemaValidator::validate(['type' => 'object'], []))->not->toBeNull()
        ->and(SchemaValidator::validate(['type' => 'array'], []))->toBeNull()
        ->and(SchemaValidator::validate(['type' => 'array'], ['key' => 1]))->not->toBeNull()
        ->and(SchemaValidator::validate(['enum' => [1]], '1'))->not->toBeNull();
});

// Mutation: compare enum objects by identity or ignore the order of array items.
it('matches composite enum values by JSON value', function (): void {
    $schema = ['enum' => [['action' => 'move', 'positions' => [1, 2]]]];

    expect(SchemaValidator::validate($schema, json_decode('{"positions":[1,2],"action":"move"}')))->toBeNull()
        ->and(SchemaValidator::validate($schema, ['action' => 'move', 'positions' => [2, 1]]))->not->toBeNull()
        ->and(SchemaValidator::validate(['enum' => [new stdClass()]], []))->not->toBeNull();
});
