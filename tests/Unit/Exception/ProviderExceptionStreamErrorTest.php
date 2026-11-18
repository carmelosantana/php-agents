<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Exception\ProviderException;

test('streamError keeps a short type and message as they are', function () {
    expect(ProviderException::streamError('overloaded_error', 'Overloaded')->getMessage())
        ->toBe('overloaded_error: Overloaded');
});

test('streamError replaces control characters and collapses whitespace in the message', function () {
    $e = ProviderException::streamError('api_error', "Line one\r\n\x1b[31mred\x1b[0m\x00\tend  ");

    expect($e->getMessage())->toBe('api_error: Line one [31mred [0m end');
});

test('streamError truncates the message to 300 characters with an ellipsis', function () {
    $message = ProviderException::streamError('api_error', str_repeat('é', 1000))->getMessage();
    $shown = substr($message, strlen('api_error: '));

    expect(mb_strlen($shown))->toBe(301)
        ->and(mb_substr($shown, -1))->toBe('…')
        ->and(mb_substr($shown, 0, 300))->toBe(str_repeat('é', 300));
});

test('streamError keeps a 300-character message whole', function () {
    expect(ProviderException::streamError('api_error', str_repeat('a', 300))->getMessage())
        ->toBe('api_error: ' . str_repeat('a', 300));
});

test('streamError replaces a type that is not a short snake_case word with error', function (string $type) {
    expect(ProviderException::streamError($type, 'Boom')->getMessage())->toBe('error: Boom');
})->with([
    'with a newline' => ["api_error\nInjected: yes"],
    'with a trailing newline' => ["api_error\n"],
    'with a space' => ['api error'],
    'upper case' => ['API_ERROR'],
    'dotted' => ['response.failed'],
    'empty' => [''],
    'too long' => [str_repeat('a', 65)],
]);

test('streamError keeps a 64-character type', function () {
    $type = str_repeat('a', 64);

    expect(ProviderException::streamError($type, 'Boom')->getMessage())->toBe($type . ': Boom');
});

test('streamError survives a message that is not valid UTF-8', function () {
    $message = ProviderException::streamError('api_error', "bad \xff\xfe bytes")->getMessage();

    expect(mb_check_encoding($message, 'UTF-8'))->toBeTrue()
        ->and($message)->toStartWith('api_error: bad ')
        ->and($message)->toEndWith(' bytes');
});
