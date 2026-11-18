<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;

test('an undeclared top-level argument is rejected', function () {
    $ran = false;
    $tool = new Tool('noop', 'Takes no arguments.', [], function (array $input) use (&$ran): string {
        $ran = true;

        return 'ok';
    });

    $result = $tool->execute(['unexpected' => 'accepted']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($ran)->toBeFalse();
});

test('the error names the unknown key so the model can retry', function () {
    $tool = new Tool('echo', 'Echo.', [
        new StringParameter('y', 'Y', required: false),
        new StringParameter('x', 'X'),
    ], fn(array $input): string => 'ok');

    $result = $tool->execute(['x' => '1', 'b' => 2, 'a' => 1]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('Unknown parameters: a, b. Allowed: x, y.');
});

test('the unknown key list is capped and each name is cut to 64 characters', function () {
    $tool = new Tool('noop', 'No arguments.', [], fn(array $input): string => 'ok');
    $input = [str_repeat('z', 100) => 1];
    foreach (range(0, 11) as $i) {
        $input[sprintf('k%02d', $i)] = $i;
    }

    $content = $tool->execute($input)->content;

    expect($content)->toBe(
        'Unknown parameters: k00, k01, k02, k03, k04, k05, k06, k07, k08, k09, …. Allowed: none.',
    );

    $long = $tool->execute([str_repeat('z', 100) => 1])->content;
    expect($long)->toBe('Unknown parameters: ' . str_repeat('z', 64) . '. Allowed: none.');
});

test('the callback receives only declared keys', function () {
    $seen = null;
    $tool = new Tool('echo', 'Echo.', [new StringParameter('x', 'X')], function (array $input) use (&$seen): string {
        $seen = $input;

        return 'ok';
    });

    $refused = $tool->execute(['x' => '1', 'extra' => 'smuggled']);

    expect($refused->status)->toBe(ToolResultStatus::Error)
        ->and($seen)->toBeNull();

    $result = $tool->execute(['x' => '1']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($seen)->toBe(['x' => '1']);
});

test('a numeric top-level key is rejected', function () {
    $ran = false;
    $tool = new Tool('echo', 'Echo.', [new StringParameter('x', 'X', required: false)], function (array $input) use (&$ran): string {
        $ran = true;

        return 'ok';
    });

    $result = $tool->execute(json_decode('{"0":"DROP"}', true));

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('Unknown parameters: 0. Allowed: x.')
        ->and($ran)->toBeFalse();
});

test('a list is not accepted as an object with declared properties', function (string $json) {
    $ran = false;
    $obj = new ObjectParameter('obj', 'Object', properties: [new StringParameter('path', 'Path', required: false)]);
    $tool = new Tool('t', 'T.', [$obj], function (array $input) use (&$ran): string {
        $ran = true;

        return 'ran';
    });

    $result = $obj->validate(json_decode($json, true));

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toBe('Parameter "obj" must be an object.')
        ->and($tool->execute(['obj' => json_decode($json, true)])->status)->toBe(ToolResultStatus::Error)
        ->and($tool->execute(['obj' => $json])->status)->toBe(ToolResultStatus::Error)
        ->and($ran)->toBeFalse();
})->with([
    'numeric key object' => ['{"0":"DROP"}'],
    'list' => ['["DROP","x"]'],
]);

test('an empty object is still accepted when every property is optional', function () {
    $obj = new ObjectParameter('obj', 'Object', properties: [new StringParameter('path', 'Path', required: false)]);

    expect($obj->validate(json_decode('{}', true))->valid)->toBeTrue()
        ->and($obj->validate('{}')->valid)->toBeTrue();
});

test('a JSON-string object with an unknown nested key is rejected', function () {
    $ran = false;
    $obj = new ObjectParameter('obj', 'Object', properties: [
        new ObjectParameter('inner', 'Inner', required: false, properties: [new StringParameter('path', 'Path', required: false)]),
    ]);
    $tool = new Tool('t', 'T.', [$obj], function (array $input) use (&$ran): string {
        $ran = true;

        return 'ran';
    });

    $result = $tool->execute(['obj' => '{"inner":{"path":"src","k":"smuggled"}}']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('has unknown properties: k.')
        ->and($ran)->toBeFalse();
});

test('an undeclared nested property is rejected', function () {
    $obj = new ObjectParameter('obj', 'Object', properties: [new StringParameter('path', 'Path')]);

    $result = $obj->validate(['path' => 'src', 'k' => 'smuggled']);

    expect($result->valid)->toBeFalse()
        ->and($result->error)->toBe('Parameter "obj" has unknown properties: k.');

    $tool = new Tool('t', 'T.', [$obj], fn(array $input): string => 'ran');
    expect($tool->execute(['obj' => ['path' => 'src', 'k' => 'smuggled']])->status)->toBe(ToolResultStatus::Error);
});

test('an undeclared property inside an array of objects is rejected', function () {
    $list = new ArrayParameter('items', 'Items', items: new ObjectParameter('item', 'Item', properties: [
        new StringParameter('id', 'Id'),
    ]));

    expect($list->validate([['id' => 'a']])->valid)->toBeTrue()
        ->and($list->validate([['id' => 'a', 'extra' => 1]])->error)->toContain('has unknown properties: extra.');
});

test('an object parameter with no declared properties still accepts any keys', function () {
    $free = new ObjectParameter('payload', 'Any payload');

    $result = $free->validate(['anything' => 1, 'else' => ['nested' => true]]);

    expect($result->valid)->toBeTrue()
        ->and($result->value)->toBe(['anything' => 1, 'else' => ['nested' => true]])
        ->and($free->toSchema())->not->toHaveKey('additionalProperties');
});

test('object schema declares additionalProperties false', function () {
    $obj = new ObjectParameter('obj', 'Object', properties: [new StringParameter('path', 'Path')]);

    expect($obj->toSchema()['additionalProperties'])->toBeFalse();
});
