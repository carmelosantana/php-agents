<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;

// Code samples in the docs pass AbstractAgent constructor arguments by name, so a renamed or
// misspelled name fails at runtime ("Unknown named parameter"). This pins the iteration limit's
// real name and the security note on providerOptions.

test('the docs pass the iteration limit as maxIter, the constructor parameter name', function (string $path) {
    $names = array_map(
        fn(ReflectionParameter $p) => $p->getName(),
        (new ReflectionMethod(AbstractAgent::class, '__construct'))->getParameters(),
    );
    $doc = (string) file_get_contents(__DIR__ . '/../../../' . $path);

    expect($names)->toContain('maxIter')
        ->and($doc)->not->toContain('maxIterations:');
})->with(['README.md', 'docs/AGENTS.md', 'docs/GETTING-STARTED.md', 'docs/ARCHITECTURE.md']);

test('the constructor table names maxIter', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect($doc)->toContain('| `maxIter` | `int` | `25` |');
});

test('the docs say providerOptions must not come from end users or tool output', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect($doc)->toContain('Treat `providerOptions` as trusted developer configuration');
});
