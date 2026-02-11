<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\EnvConfig;

test('get returns value from constructor array', function () {
    $config = new EnvConfig(['FOO' => 'bar']);

    expect($config->get('FOO'))->toBe('bar');
});

test('get returns default when key not found', function () {
    $config = new EnvConfig([]);

    expect($config->get('MISSING', 'default'))->toBe('default');
});

test('get returns null when key not found and no default', function () {
    $config = new EnvConfig([]);

    expect($config->get('MISSING'))->toBeNull();
});

test('has returns true for existing key', function () {
    $config = new EnvConfig(['FOO' => 'bar']);

    expect($config->has('FOO'))->toBeTrue();
});

test('has returns false for missing key', function () {
    $config = new EnvConfig([]);

    expect($config->has('MISSING'))->toBeFalse();
});

test('getString returns string value', function () {
    $config = new EnvConfig(['NAME' => 'test']);

    expect($config->getString('NAME'))->toBe('test');
});

test('getString returns default when missing', function () {
    $config = new EnvConfig([]);

    expect($config->getString('MISSING', 'fallback'))->toBe('fallback');
});

test('getInt returns integer value', function () {
    $config = new EnvConfig(['PORT' => '8080']);

    expect($config->getInt('PORT'))->toBe(8080);
});

test('getInt returns default when missing', function () {
    $config = new EnvConfig([]);

    expect($config->getInt('PORT', 3000))->toBe(3000);
});

test('getBool recognizes truthy values', function () {
    expect((new EnvConfig(['A' => '1']))->getBool('A'))->toBeTrue();
    expect((new EnvConfig(['A' => 'true']))->getBool('A'))->toBeTrue();
    expect((new EnvConfig(['A' => 'yes']))->getBool('A'))->toBeTrue();
    expect((new EnvConfig(['A' => 'on']))->getBool('A'))->toBeTrue();
});

test('getBool returns false for non-truthy values', function () {
    expect((new EnvConfig(['A' => '0']))->getBool('A'))->toBeFalse();
    expect((new EnvConfig(['A' => 'false']))->getBool('A'))->toBeFalse();
    expect((new EnvConfig(['A' => 'no']))->getBool('A'))->toBeFalse();
});

test('getBool returns default when missing', function () {
    $config = new EnvConfig([]);

    expect($config->getBool('MISSING', true))->toBeTrue();
});

test('getArray splits comma-separated values', function () {
    $config = new EnvConfig(['TAGS' => 'a, b, c']);

    expect($config->getArray('TAGS'))->toBe(['a', 'b', 'c']);
});

test('getArray returns empty array when missing', function () {
    $config = new EnvConfig([]);

    expect($config->getArray('MISSING'))->toBe([]);
});

test('get handles empty string env value correctly', function () {
    $config = new EnvConfig(['EMPTY' => '']);

    expect($config->get('EMPTY'))->toBe('');
    expect($config->get('EMPTY', 'default'))->toBe('');
});

test('get handles zero string env value correctly', function () {
    $config = new EnvConfig(['ZERO' => '0']);

    expect($config->get('ZERO'))->toBe('0');
    expect($config->get('ZERO', 'default'))->toBe('0');
});
