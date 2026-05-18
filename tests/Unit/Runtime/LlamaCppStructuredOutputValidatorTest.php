<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppStructuredOutputValidator;

test('structured output validator accepts matching object schemas', function () {
    $validator = new LlamaCppStructuredOutputValidator();

    $result = $validator->decodeAndValidate(
        '{"name":"Alice","tags":["admin"]}',
        [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ],
    );

    expect($result)->toBe(['name' => 'Alice', 'tags' => ['admin']]);
});

test('structured output validator rejects unsupported properties', function () {
    $validator = new LlamaCppStructuredOutputValidator();

    expect(fn() => $validator->decodeAndValidate(
        '{"name":"Alice","extra":true}',
        [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
            'additionalProperties' => false,
        ],
    ))->toThrow(RuntimeException::class, 'unsupported property');
});