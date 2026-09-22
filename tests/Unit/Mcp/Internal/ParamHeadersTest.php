<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Internal\HeaderValue;
use CarmeloSantana\PHPAgents\Mcp\Internal\ParamHeaders;

// 2026-07-28 streamable-http: §Value Encoding and §Custom Headers from Tool Parameters.

test('a plain visible-ASCII value is sent as is; anything else is base64-wrapped', function () {
    expect(HeaderValue::encode('search_issues'))->toBe('search_issues')
        ->and(HeaderValue::encode('us-east 1'))->toBe('us-east 1')
        ->and(HeaderValue::encode('café'))->toBe('=?base64?' . base64_encode('café') . '?=')
        ->and(HeaderValue::encode(' padded'))->toBe('=?base64?' . base64_encode(' padded') . '?=')
        ->and(HeaderValue::encode("line\nbreak"))->toBe('=?base64?' . base64_encode("line\nbreak") . '?=')
        ->and(HeaderValue::encode('=?base64?abc?='))->toBe('=?base64?' . base64_encode('=?base64?abc?=') . '?=');
});

test('the empty string and a tab are wrapped too, which over-encodes rather than risking an empty or unsafe header value', function () {
    expect(HeaderValue::encode(''))->toBe('=?base64??=')
        ->and(HeaderValue::encode("a\tb"))->toBe('=?base64?' . base64_encode("a\tb") . '?=');
});

test('the degenerate sentinel, whose prefix and suffix overlap, is wrapped as well, and the markers stay case-sensitive', function () {
    expect(HeaderValue::encode('=?base64?='))->toBe('=?base64?' . base64_encode('=?base64?=') . '?=')
        ->and(HeaderValue::encode('=?BASE64?x?='))->toBe('=?BASE64?x?=');
});

test('a trailing newline is wrapped: an unanchored $ would let one through and put a CR/LF into a header', function () {
    expect(HeaderValue::encode("trailing\n"))->toBe('=?base64?' . base64_encode("trailing\n") . '?=')
        ->and(HeaderValue::encode("trailing\r"))->toBe('=?base64?' . base64_encode("trailing\r") . '?=')
        ->and(HeaderValue::encode("trailing\r\n"))->toBe('=?base64?' . base64_encode("trailing\r\n") . '?=');
});

test('annotated primitive properties become header mappings, including nested ones reached through properties', function () {
    $schema = ['type' => 'object', 'properties' => [
        'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
        'dry' => ['type' => 'boolean', 'x-mcp-header' => 'Dry-Run'],
        'opts' => ['type' => 'object', 'properties' => ['shard' => ['type' => ['integer', 'null'], 'x-mcp-header' => 'Shard']]],
        'q' => ['type' => 'string'],
    ]];

    expect(ParamHeaders::extract($schema))->toBe([
        ['path' => ['region'], 'header' => 'Region'],
        ['path' => ['dry'], 'header' => 'Dry-Run'],
        ['path' => ['opts', 'shard'], 'header' => 'Shard'],
    ]);
});

test('a schema without annotations maps to nothing', function () {
    expect(ParamHeaders::extract(['type' => 'object']))->toBe([]);
});

test('an invalid annotation drops the tool', function (array $schema) {
    expect(ParamHeaders::extract($schema))->toBeNull();
})->with([
    'empty' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'x-mcp-header' => '']]]],
    'not a token' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'x-mcp-header' => 'Bad Name']]]],
    'a token with a trailing newline, which an unanchored $ would accept into a header name' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'x-mcp-header' => "Region\n"]]]],
    'not a string' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'x-mcp-header' => 7]]]],
    'duplicate, case-insensitively' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'x-mcp-header' => 'Region'], 'b' => ['type' => 'string', 'x-mcp-header' => 'region']]]],
    'number type' => [['type' => 'object', 'properties' => ['a' => ['type' => 'number', 'x-mcp-header' => 'A']]]],
    'object type' => [['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'x-mcp-header' => 'A']]]],
    'no type at all' => [['type' => 'object', 'properties' => ['a' => ['x-mcp-header' => 'A']]]],
    'under items' => [['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'string', 'x-mcp-header' => 'A']]]]],
    'under a combinator' => [['type' => 'object', 'properties' => ['a' => ['anyOf' => [['type' => 'string', 'x-mcp-header' => 'A']]]]]],
    'under a conditional' => [['type' => 'object', 'if' => ['properties' => ['a' => ['type' => 'string']]], 'then' => ['properties' => ['b' => ['type' => 'string', 'x-mcp-header' => 'A']]]]],
    'in $defs' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']], '$defs' => ['Opts' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string', 'x-mcp-header' => 'A']]]]]],
    'behind a $ref' => [['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/Opts']], '$defs' => ['Opts' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string', 'x-mcp-header' => 'A']]]]]],
]);

test('an x-mcp-header key inside instance data is not an annotation, so the tool survives', function (array $schema, array $expected) {
    expect(ParamHeaders::extract($schema))->toBe($expected);
})->with([
    'in a default' => [['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'default' => ['x-mcp-header' => 'A']]]], []],
    'in a const' => [['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'const' => ['x-mcp-header' => 'A']]]], []],
    'in an enum member' => [['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'enum' => [['x-mcp-header' => 'A'], ['b' => 1]]]]], []],
    'in examples' => [['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'examples' => [['x-mcp-header' => 'A']]]]], []],
    'beside a valid annotation, which keeps its mapping' => [
        ['type' => 'object', 'properties' => [
            'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            'shape' => ['type' => 'object', 'default' => ['x-mcp-header' => 'not an annotation']],
        ]],
        [['path' => ['region'], 'header' => 'Region']],
    ],
]);

test('a property may be named like a keyword, because under properties every key is a name', function (array $schema, array $expected) {
    expect(ParamHeaders::extract($schema))->toBe($expected);
})->with([
    'named x-mcp-header, beside a real annotation' => [
        ['type' => 'object', 'properties' => [
            'x-mcp-header' => ['type' => 'string'],
            'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
        ]],
        [['path' => ['region'], 'header' => 'Region']],
    ],
    'named default, carrying the annotation itself' => [
        ['type' => 'object', 'properties' => ['default' => ['type' => 'string', 'x-mcp-header' => 'Default']]],
        [['path' => ['default'], 'header' => 'Default']],
    ],
    'named default, holding a nested annotated property' => [
        ['type' => 'object', 'properties' => ['default' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'x-mcp-header' => 'X']]]]],
        [['path' => ['default', 'x'], 'header' => 'X']],
    ],
]);

test('arguments become Mcp-Param headers with the spec conversions, skipping absent and null values', function () {
    $map = [
        ['path' => ['region'], 'header' => 'Region'],
        ['path' => ['dry'], 'header' => 'Dry-Run'],
        ['path' => ['opts', 'shard'], 'header' => 'Shard'],
        ['path' => ['missing'], 'header' => 'Missing'],
        ['path' => ['nothing'], 'header' => 'Nothing'],
        ['path' => ['wrong'], 'header' => 'Wrong'],
    ];

    expect(ParamHeaders::headers($map, ['region' => 'eü', 'dry' => false, 'opts' => ['shard' => 12], 'nothing' => null, 'wrong' => ['x']]))->toBe([
        'Mcp-Param-Region' => '=?base64?' . base64_encode('eü') . '?=',
        'Mcp-Param-Dry-Run' => 'false',
        'Mcp-Param-Shard' => '12',
    ]);
});

test('an integral float converts like an integer, because a server compares header and body numerically', function () {
    $map = [
        ['path' => ['shard'], 'header' => 'Shard'],
        ['path' => ['back'], 'header' => 'Back'],
        ['path' => ['big'], 'header' => 'Big'],
        ['path' => ['ratio'], 'header' => 'Ratio'],
        ['path' => ['endless'], 'header' => 'Endless'],
        ['path' => ['nothing'], 'header' => 'Nothing'],
    ];

    expect(ParamHeaders::headers($map, ['shard' => 12.0, 'back' => -7.0, 'big' => 1.0e18, 'ratio' => 1.5, 'endless' => INF, 'nothing' => NAN]))->toBe([
        'Mcp-Param-Shard' => '12',
        'Mcp-Param-Back' => '-7',
        'Mcp-Param-Big' => '1000000000000000000',
    ]);
});
