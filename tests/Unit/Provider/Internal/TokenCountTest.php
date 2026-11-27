<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\Internal\TokenCount;

dataset('token counts', [
    'zero int' => [0, 0],
    'positive int' => [12, 12],
    'max int' => [PHP_INT_MAX, PHP_INT_MAX],
    'negative int' => [-1, null],
    'integral float' => [12.0, 12],
    'zero float' => [0.0, 0],
    'negative zero float' => [-0.0, 0],
    'fractional float' => [12.5, null],
    'negative float' => [-3.0, null],
    'NaN' => [NAN, null],
    'INF' => [INF, null],
    'negative INF' => [-INF, null],
    'float past int range' => [(float) PHP_INT_MAX, null],
    'digit string' => ['12', 12],
    'zero string' => ['0', 0],
    'leading zeros' => ['007', 7],
    'max int string' => [(string) PHP_INT_MAX, PHP_INT_MAX],
    'overflowing string' => ['9223372036854775808', null],
    'long overflowing string' => ['99999999999999999999999', null],
    'empty string' => ['', null],
    'signed string' => ['+12', null],
    'negative string' => ['-12', null],
    'decimal string' => ['12.0', null],
    'exponent string' => ['1e3', null],
    'leading space' => [' 12', null],
    'trailing space' => ['12 ', null],
    'trailing newline' => ["12\n", null],
    'hex string' => ['0x1A', null],
    'non-ASCII digits' => ['١٢', null],
    'true' => [true, null],
    'false' => [false, null],
    'null' => [null, null],
    'array' => [[12], null],
]);

test('TokenCount::from accepts only non-negative integral counts', function (mixed $raw, ?int $expected) {
    expect(TokenCount::from($raw))->toBe($expected);
})->with('token counts');
