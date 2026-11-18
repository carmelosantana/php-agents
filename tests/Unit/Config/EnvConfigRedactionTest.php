<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\EnvConfig;

afterEach(function () {
    putenv('PHPAGENTS_TEST_KEY');
});

test('serialize does not contain a secret', function () {
    $config = new EnvConfig(['OPENAI_API_KEY' => 'SECRET_MARKER_123']);

    expect(serialize($config))->not->toContain('SECRET_MARKER_123');
});

test('var_dump and print_r do not contain a secret', function () {
    $config = new EnvConfig(['OPENAI_API_KEY' => 'SECRET_MARKER_123']);

    ob_start();
    var_dump($config);
    $dumped = (string) ob_get_clean();
    $printed = print_r($config, true);

    expect($dumped)->not->toContain('SECRET_MARKER_123')
        ->and($dumped)->toContain('OPENAI_API_KEY')
        ->and($dumped)->toContain('[redacted]')
        ->and($printed)->not->toContain('SECRET_MARKER_123')
        ->and($printed)->toContain('OPENAI_API_KEY')
        ->and($printed)->toContain('[redacted]');
});

test('an unserialized copy drops captured values and falls through to getenv', function () {
    putenv('PHPAGENTS_TEST_KEY=real');
    $config = new EnvConfig(['PHPAGENTS_TEST_KEY' => 'SECRET_MARKER_123']);

    $serialized = serialize($config);
    $copy = unserialize($serialized);

    expect($serialized)->not->toContain('SECRET_MARKER_123')
        ->and($copy)->toBeInstanceOf(EnvConfig::class)
        ->and($copy->get('PHPAGENTS_TEST_KEY'))->toBe('real')
        ->and($copy->get('PHPAGENTS_MISSING_KEY'))->toBeNull()
        ->and($config->get('PHPAGENTS_TEST_KEY'))->toBe('SECRET_MARKER_123');
});

test('a crafted serialized payload cannot inject environment values', function () {
    putenv('PHPAGENTS_TEST_KEY=real');
    $payload = str_replace(
        's:3:"env";a:0:{}',
        's:3:"env";a:2:{s:18:"PHPAGENTS_TEST_KEY";s:8:"injected";s:20:"PHPAGENTS_INJECT_KEY";s:8:"injected";}',
        serialize(new EnvConfig()),
    );
    $copy = unserialize($payload);

    expect($payload)->toContain('injected')
        ->and($copy)->toBeInstanceOf(EnvConfig::class)
        ->and($copy->get('PHPAGENTS_TEST_KEY'))->toBe('real')
        ->and($copy->get('PHPAGENTS_INJECT_KEY'))->toBeNull()
        ->and($copy->has('PHPAGENTS_INJECT_KEY'))->toBeFalse();
});
