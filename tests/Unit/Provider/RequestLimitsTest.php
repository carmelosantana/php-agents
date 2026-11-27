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

test('redirectRefused() wraps the redirection exception as a refusal', function () {
    $client = new Symfony\Component\HttpClient\MockHttpClient([
        new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 307]),
    ]);
    $response = $client->request('GET', 'https://example.test/');
    $response->getStatusCode(); // Marks the status as checked so the destructor does not throw.
    $redirect = new Symfony\Component\HttpClient\Exception\RedirectionException($response);

    $refused = ProviderException::redirectRefused($redirect);

    expect($refused->getMessage())
        ->toBe(sprintf('Provider request refused: redirect not followed (%s)', $redirect->getMessage()))
        ->and($refused->getPrevious())->toBe($redirect);
});

test('a negative limit is refused', function (array $arguments) {
    expect(fn() => new RequestLimits(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'idleTimeout' => [['idleTimeout' => -1.0]],
    'maxDuration' => [['maxDuration' => -0.5]],
    'maxResponseBytes' => [['maxResponseBytes' => -1]],
    'maxRedirects' => [['maxRedirects' => -1]],
    'tickInterval' => [['tickInterval' => -0.25]],
]);

test('the tick interval defaults to a quarter second, 0.0 turns ticks off, and apply() leaves it out', function () {
    $off = new RequestLimits(tickInterval: 0.0);
    $offOptions = $off->apply([]);
    $defaultOptions = (new RequestLimits())->apply([]);

    // apply() builds a fresh on_progress closure per call, so compare it by shape, the rest by value.
    expect((new RequestLimits())->tickInterval)->toBe(0.25)
        ->and($off->tickInterval)->toBe(0.0)
        ->and($offOptions['on_progress'])->toBeCallable()
        ->and($defaultOptions['on_progress'])->toBeCallable()
        ->and(array_diff_key($offOptions, ['on_progress' => true]))
        ->toBe(array_diff_key($defaultOptions, ['on_progress' => true]));
});

test('zero limits are accepted', function () {
    $limits = new RequestLimits(idleTimeout: 0.0, maxDuration: 0.0, maxResponseBytes: 0, maxRedirects: 0);

    expect($limits->maxResponseBytes)->toBe(0);
});

test('providers keep their limits in a property named requestLimits', function (string $class) {
    $reflection = new ReflectionClass($class);

    expect($reflection->hasProperty('requestLimits'))->toBeTrue()
        ->and($reflection->hasProperty('limits'))->toBeFalse();
})->with([
    CarmeloSantana\PHPAgents\Provider\AbstractProvider::class,
    CarmeloSantana\PHPAgents\Embedding\OpenAIEmbeddingProvider::class,
    CarmeloSantana\PHPAgents\Embedding\OllamaEmbeddingProvider::class,
]);
