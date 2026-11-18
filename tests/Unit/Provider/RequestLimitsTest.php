<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;

test('apply() sets timeout, max_duration and max_redirects 0 by default', function () {
    $options = (new RequestLimits())->apply(['headers' => ['X-A' => 'b']]);

    expect($options['headers'])->toBe(['X-A' => 'b'])
        ->and($options['timeout'])->toBe(300.0)
        ->and($options['max_duration'])->toBe(600.0)
        ->and($options['max_redirects'])->toBe(0)
        ->and($options['on_progress'])->toBeInstanceOf(Closure::class)
        ->and(RequestLimits::DEFAULT_MAX_RESPONSE_BYTES)->toBe(33_554_432);
});

test('apply() overwrites a caller max_redirects', function () {
    $options = (new RequestLimits())->apply(['max_redirects' => 20]);

    expect($options['max_redirects'])->toBe(0);
});

test('apply() omits max_duration when it is 0.0', function () {
    $options = (new RequestLimits(maxDuration: 0.0))->apply(['max_duration' => 5.0]);

    expect($options)->not->toHaveKey('max_duration');
});

test('on_progress throws past maxResponseBytes', function () {
    $options = (new RequestLimits())->apply([]);

    expect(fn () => $options['on_progress'](33_554_433, 0, []))
        ->toThrow(ProviderException::class, 'exceeded 33554432');
});

test('on_progress does not throw at maxResponseBytes or when the cap is 0', function () {
    $capped = (new RequestLimits())->apply([]);
    $uncapped = (new RequestLimits(maxResponseBytes: 0))->apply([]);

    $capped['on_progress'](33_554_432, 0, []);
    $uncapped['on_progress'](PHP_INT_MAX, 0, []);

    expect(true)->toBeTrue();
});

test('on_progress throws when redirect_count exceeds maxRedirects', function () {
    $options = (new RequestLimits())->apply([]);

    expect(fn () => $options['on_progress'](0, 0, ['redirect_count' => 1]))
        ->toThrow(ProviderException::class, 'redirect refused');

    $three = (new RequestLimits(maxRedirects: 3))->apply([]);
    $three['on_progress'](0, 0, ['redirect_count' => 3]);

    expect(fn () => $three['on_progress'](0, 0, ['redirect_count' => 4]))
        ->toThrow(ProviderException::class, 'redirect refused');
});

test('a caller on_progress still runs', function () {
    $calls = [];
    $options = (new RequestLimits())->apply([
        'on_progress' => function (int $dlNow, int $dlSize, array $info) use (&$calls): void {
            $calls[] = [$dlNow, $dlSize, $info];
        },
    ]);

    $options['on_progress'](10, 20, ['redirect_count' => 0]);

    expect($calls)->toBe([[10, 20, ['redirect_count' => 0]]]);
});

test('the caller on_progress runs before the cap throws', function () {
    $ran = false;
    $options = (new RequestLimits(maxResponseBytes: 5))->apply([
        'on_progress' => function () use (&$ran): void {
            $ran = true;
        },
    ]);

    expect(fn () => $options['on_progress'](6, 0, []))->toThrow(ProviderException::class);
    expect($ran)->toBeTrue();
});

test('requestRefused() formats its message', function () {
    expect(ProviderException::requestRefused('why')->getMessage())
        ->toBe('Provider request refused: why');
});
