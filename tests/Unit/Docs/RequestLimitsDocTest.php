<?php

declare(strict_types=1);

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
        ->toBe(['idleTimeout', 'maxDuration', 'maxResponseBytes', 'maxRedirects']);

    foreach ($parameters as $parameter) {
        $default = $parameter->getDefaultValue();
        $shown = is_float($default) ? var_export($default, true) : number_format($default, 0, '', '_');

        expect($doc)->toContain(sprintf('| `%s` | `%s` |', $parameter->getName(), $shown));
    }
});
