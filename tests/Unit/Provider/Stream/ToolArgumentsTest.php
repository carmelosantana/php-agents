<?php

declare(strict_types=1);

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
