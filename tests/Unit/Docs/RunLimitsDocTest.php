<?php

declare(strict_types=1);

// maxTools is an execution boundary, not just a cap on the provider payload. These pin the
// RunLimits row in the constructor table, the opt-out and grant names, and the removal of the
// old claim that hidden tools stay executable.

test('the constructor table lists runLimits, the trailing constructor parameter', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');
    $parameters = (new ReflectionMethod(\CarmeloSantana\PHPAgents\Agent\AbstractAgent::class, '__construct'))->getParameters();
    $last = $parameters[array_key_last($parameters)];

    expect($last->getName())->toBe('runLimits')
        ->and($last->getDefaultValue())->toBeNull()
        ->and($parameters[count($parameters) - 2]->getName())->toBe('providerOptions')
        ->and($doc)->toContain('| `runLimits` | `?RunLimits` | `null` |')
        ->and($doc)->toContain('## Run Limits')
        ->and($doc)->toContain('executeUnadvertisedTools')
        ->and($doc)->toContain('ToolGrantInterface')
        ->and($doc)->toContain('Tool call limit reached for this run.');
});

test('the tools guide says hidden tools are not executed', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/TOOLS-AND-TOOLKITS.md');

    expect($doc)->toContain('A tool that `maxTools` hides is not executed')
        ->and($doc)->toContain('executeUnadvertisedTools')
        ->and($doc)->toContain('ToolGrantInterface')
        ->and($doc)->not->toContain('can still be executed');
});
