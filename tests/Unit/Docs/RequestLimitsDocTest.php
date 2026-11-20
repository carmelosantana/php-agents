<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\Internal\PublicImageDownloader;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;

// docs/PROVIDERS.md lists the RequestLimits defaults in a table. This reads the defaults from
// the constructor by reflection, so a changed default fails here until the doc is updated.

test('the request limits section documents withRequestLimits()', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');

    expect($doc)->toContain('## Request Limits')
        ->and($doc)->toContain('->withRequestLimits(new RequestLimits(maxDuration: 45.0))');
});

test('the defaults table matches the RequestLimits constructor defaults', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');
    $parameters = (new ReflectionMethod(RequestLimits::class, '__construct'))->getParameters();

    expect(array_map(fn(ReflectionParameter $p) => $p->getName(), $parameters))
        ->toBe(['idleTimeout', 'maxDuration', 'maxResponseBytes', 'maxRedirects', 'tickInterval', 'maxToolArgumentBytes', 'maxToolArgumentDepth']);

    foreach ($parameters as $parameter) {
        $default = $parameter->getDefaultValue();
        $shown = is_float($default) ? var_export($default, true) : number_format($default, 0, '', '_');

        expect($doc)->toContain(sprintf('| `%s` | `%s` |', $parameter->getName(), $shown));
    }
});

test('the docs say maxDuration 0.0 leaves the client max_duration default in place', function (string $path) {
    $doc = (string) file_get_contents(__DIR__ . '/../../../' . $path);

    expect((new RequestLimits(maxDuration: 0.0))->apply([]))->not->toHaveKey('max_duration')
        ->and($doc)->not->toContain('`max_duration` replace')
        ->and($doc)->toContain("client's own `max_duration` default applies");
})->with(['docs/PROVIDERS.md', 'docs/adr/0003-provider-request-limits.md']);

test('the docs say PHP\'s filter already refuses IPv4-mapped IPv6 addresses', function (string $path) {
    $doc = (string) file_get_contents(__DIR__ . '/../../../' . $path);

    expect(filter_var('::ffff:127.0.0.1', FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))->toBeFalse()
        ->and(filter_var('::ffff:8.8.8.8', FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))->toBeFalse()
        ->and($doc)->not->toContain('`::ffff:0:0/96` (IPv4-mapped) and')
        ->and($doc)->toContain('IPv4-mapped `::ffff:0:0/96`, which PHP\'s filter already refuses');
})->with(['docs/PROVIDERS.md', 'docs/adr/0003-provider-request-limits.md']);

test('the docs list every range in the Gemini IPv6 deny-list', function (string $path) {
    $doc = (string) file_get_contents(__DIR__ . '/../../../' . $path);
    $ranges = (new ReflectionClassConstant(PublicImageDownloader::class, 'DENIED_IPV6_RANGES'))->getValue();

    expect($ranges)->not->toBeEmpty();
    foreach ($ranges as [$prefix, $bits]) {
        expect($doc)->toContain(sprintf('`%s/%d`', $prefix, $bits));
    }
    expect($doc)->toContain('`::/8` (reserved)');
})->with(['docs/PROVIDERS.md', 'docs/adr/0003-provider-request-limits.md']);
