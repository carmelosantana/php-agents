<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;

// docs/AGENTS.md documents the providerOptions constructor parameter. This pins the table row
// and the example against the constructor, so a renamed or re-defaulted parameter fails here.

test('the constructor table and example document providerOptions', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');
    $parameters = (new ReflectionMethod(AbstractAgent::class, '__construct'))->getParameters();
    $byName = array_column(array_map(fn(ReflectionParameter $p) => [$p->getName(), $p], $parameters), 1, 0);

    expect($byName)->toHaveKey('providerOptions')
        ->and($byName['providerOptions']->getDefaultValue())->toBe([])
        ->and($doc)->toContain('| `providerOptions` | `array` | `[]` |')
        ->and($doc)->toContain("providerOptions: ['max_tokens' => 8192]");
});
