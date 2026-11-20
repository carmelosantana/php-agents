<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolArguments;

test('decode returns an empty object for empty or whitespace arguments', function (string $json) {
    expect(ToolArguments::decode($json))->toBe([]);
})->with(['empty' => [''], 'whitespace' => [" \n "]]);

test('decode returns the decoded JSON object', function () {
    expect(ToolArguments::decode('{"city":"NYC","n":{"a":1}}'))->toBe(['city' => 'NYC', 'n' => ['a' => 1]])
        ->and(ToolArguments::decode('{}'))->toBe([]);
});

test('decode returns null for arguments that are not a JSON object', function (string $json) {
    expect(ToolArguments::decode($json))->toBeNull();
})->with(['truncated' => ['{"a":'], 'array' => ['[1,2]'], 'empty array' => ['[]'], 'scalar' => ['42'], 'string' => ['"x"'], 'null' => ['null']]);

test('toJson keeps wire strings and re-encodes object-shaped arguments', function (mixed $arguments, string $expected) {
    expect(ToolArguments::toJson($arguments))->toBe($expected);
})->with([
    'string' => ['{"a"', '{"a"'],
    'empty string' => ['', ''],
    'missing' => [null, ''],
    'empty object decoded to []' => [[], '{}'],
    'object' => [['a' => 1], '{"a":1}'],
    'list' => [[1, 2], '[1,2]'],
    'scalar' => [42, '42'],
]);

test('fromDecoded reads arguments from a decoded response body', function (mixed $value, ?array $expected) {
    expect(ToolArguments::fromDecoded($value))->toBe($expected);
})->with([
    'missing' => [null, []],
    'empty object decoded as []' => [[], []],
    'object' => [['city' => 'NYC'], ['city' => 'NYC']],
    'wire string' => ['{"city":"NYC"}', ['city' => 'NYC']],
    'empty string' => ['', []],
    'truncated string' => ['{"city":', null],
    'list' => [[1, 2], null],
    'scalar' => [42, null],
]);

/** A JSON object nested $depth levels deep: {"a":{"a":…{}…}}. */
function nestedArgumentsJson(int $depth): string
{
    return str_repeat('{"a":', $depth - 1) . '{}' . str_repeat('}', $depth - 1);
}

test('decode drops arguments over the default 4 MiB cap', function () {
    $prefix = '{"a":"';
    $atCap = $prefix . str_repeat('x', 4_194_304 - strlen($prefix) - 2) . '"}';
    expect(strlen($atCap))->toBe(4_194_304);

    // Compared as booleans so a failure does not print 4 MiB.
    expect(ToolArguments::decode($atCap) !== null)->toBeTrue()
        ->and(ToolArguments::decode(substr($atCap, 0, -2) . 'x"}') === null)->toBeTrue();
});

test('decode drops arguments nested deeper than the default 64 levels', function () {
    expect(ToolArguments::decode(nestedArgumentsJson(64)))->toBeArray()
        ->and(ToolArguments::decode(nestedArgumentsJson(65)))->toBeNull();
});

test('decode applies the byte and depth caps of the given request limits', function () {
    $limits = new RequestLimits(maxToolArgumentBytes: 10, maxToolArgumentDepth: 2);

    expect(ToolArguments::decode('{"a":"xx"}', $limits))->toBe(['a' => 'xx'])
        ->and(ToolArguments::decode('{"a":"xxx"}', $limits))->toBeNull()
        ->and(ToolArguments::decode('{"a":{}}', $limits))->toBe(['a' => []])
        ->and(ToolArguments::decode('{"a":[1]}', $limits))->toBe(['a' => [1]])
        ->and(ToolArguments::decode('{"a":[[]]}', $limits))->toBeNull();
});

test('a zero byte cap is no cap and a zero depth cap is json_decode\'s default', function () {
    $limits = new RequestLimits(maxToolArgumentBytes: 0, maxToolArgumentDepth: 0);

    expect(ToolArguments::decode(nestedArgumentsJson(200), $limits))->toBeArray()
        ->and(ToolArguments::decode(nestedArgumentsJson(600), $limits))->toBeNull();
});

test('decode reads a top-level object after leading JSON whitespace', function () {
    expect(ToolArguments::decode(" \n\t{\"a\":1}"))->toBe(['a' => 1])
        ->and(ToolArguments::decode(' [1]'))->toBeNull();
});

test('fromDecoded applies the caps to arguments that arrive decoded', function () {
    $limits = new RequestLimits(maxToolArgumentBytes: 10, maxToolArgumentDepth: 2);

    expect(ToolArguments::fromDecoded(['a' => 'xx'], $limits))->toBe(['a' => 'xx'])
        ->and(ToolArguments::fromDecoded(['a' => 'xxx'], $limits))->toBeNull()
        ->and(ToolArguments::fromDecoded(['a' => [[1]]], $limits))->toBeNull()
        ->and(ToolArguments::fromDecoded('{"a":"xxx"}', $limits))->toBeNull()
        ->and(ToolArguments::fromDecoded(json_decode(nestedArgumentsJson(65), true)))->toBeNull();
});
