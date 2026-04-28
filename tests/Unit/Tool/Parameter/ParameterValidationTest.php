<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\MapParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

test('string parameter validates pattern, max length, and enum constraints', function () {
    $parameter = new StringParameter(
        'slug',
        'Slug value',
        pattern: '/^[a-z-]+$/',
        maxLength: 12,
        enum: ['valid-slug', 'other-slug'],
    );

    expect($parameter->validate('valid-slug')->valid)->toBeTrue();
    expect($parameter->validate('INVALID')->error)->toContain('required pattern');
    expect($parameter->validate('way-too-long-for-rule')->error)->toContain('at most 12 characters');
    expect($parameter->validate('missing-slug')->error)->toContain('must be one of');
});

test('number parameter validates integer coercion and bounds', function () {
    $parameter = new NumberParameter('count', 'Result count', integer: true, minimum: 1, maximum: 3);

    $valid = $parameter->validate('2');

    expect($valid->valid)->toBeTrue();
    expect($valid->value)->toBe(2);
    expect($parameter->validate('2.5')->error)->toContain('must be an integer');
    expect($parameter->validate(0)->error)->toContain('at least 1');
    expect($parameter->validate(4)->error)->toContain('at most 3');
});

test('bool and enum parameters validate their allowed types and values', function () {
    $bool = new BoolParameter('verbose', 'Verbose mode');
    $enum = new EnumParameter('stage', 'Artifact stage', ['draft', 'review', 'final']);

    expect($bool->validate(true)->valid)->toBeTrue();
    expect($bool->validate('true')->error)->toContain('must be a boolean');
    expect($enum->validate('review')->valid)->toBeTrue();
    expect($enum->validate('archive')->error)->toContain('must be one of');
});

test('array, object, and map parameters validate nested structures', function () {
    $tags = new ArrayParameter('tags', 'Tags', items: new StringParameter('tag', 'Tag value', maxLength: 5));
    $options = new ObjectParameter('options', 'Options', properties: [
        new StringParameter('path', 'Path', required: true),
        new BoolParameter('recursive', 'Recursive', required: false),
    ]);
    $headers = new MapParameter('headers', 'Headers', required: false);

    expect($tags->validate(['a', 'bb'])->valid)->toBeTrue();
    expect($tags->validate(['toolong'])->error)->toContain('invalid item at index 0');

    $validObject = $options->validate(['path' => 'src', 'recursive' => true]);
    expect($validObject->valid)->toBeTrue();
    expect($options->validate(['recursive' => true])->error)->toContain('missing required properties');
    expect($options->validate(['path' => 'src', 'recursive' => 'yes'])->error)->toContain('options.recursive');

    expect($headers->validate(['Accept' => 'application/json'])->valid)->toBeTrue();
    expect($headers->validate('not-an-object')->error)->toContain('must be an object');
});