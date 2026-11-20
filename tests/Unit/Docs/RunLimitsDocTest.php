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

test('the run limits section documents the cumulative limits and that they are cooperative', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');
    $fields = array_map(
        fn(ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(\CarmeloSantana\PHPAgents\Agent\RunLimits::class, '__construct'))->getParameters(),
    );

    foreach ($fields as $field) {
        expect($doc)->toContain("| `{$field}` |");
    }

    expect($fields)->toBe([
        'maxToolCallsPerTurn', 'maxToolCallsPerRun', 'executeUnadvertisedTools',
        'maxRunSeconds', 'maxResponseBytes', 'maxEstimatedTokens', 'maxInputTokens',
    ])
        ->and($doc)->toContain('### Cumulative limits')
        ->and($doc)->toContain('`Run time limit reached.`')
        ->and($doc)->toContain('`Response size limit reached.`')
        ->and($doc)->toContain('`Token limit reached.`')
        ->and($doc)->toContain('`Input is too large.`')
        ->and($doc)->toContain('The limits are cooperative')
        ->and($doc)->toContain('`RequestLimits::$maxResponseBytes`')
        ->and($doc)->toContain('`ContextWindow::report()` keeps the higher of the reported total and that estimate.');
});

test('the run limits docs say what the per-turn cap counts and where the token and input caps apply', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');
    $runLimits = (string) file_get_contents(__DIR__ . '/../../../src/Agent/RunLimits.php');

    expect($doc)->toContain('| `maxToolCallsPerTurn` | `0` | Tool calls kept from one provider response, counted as they stream (denied and unknown calls included); `0` means no cap |')
        ->and($runLimits)->toContain('caps the tool calls kept from one provider response, counted as they')
        ->and($doc)->toContain('| `tool_call_limit` | The call opened past')
        ->and($doc)->toContain('| `maxEstimatedTokens` | Before each provider call, on every stream event and after each response | `Token limit reached.` |')
        ->and($doc)->toContain('| `maxInputTokens` | Before the first provider call, and on each pending input before it is added | `Input is too large.` |')
        ->and($doc)->toContain('An endless stream of empty chunks is bounded only by `maxRunSeconds`')
        ->and($doc)->not->toContain('between stream chunks')
        ->and($doc)->not->toContain('between chunks')
        ->and($doc)->toContain('ends `BudgetExhausted` even when it carried a final answer');
});

test('the granted tools docs say a successful result and the most recent call', function (string $path) {
    $doc = (string) file_get_contents(__DIR__ . '/../../../' . $path);

    expect($doc)->not->toContain('non-error')
        ->and($doc)->toContain('successful result')
        ->and($doc)->toContain('most recent call');
})->with(['docs/AGENTS.md', 'src/Contract/ToolGrantInterface.php']);
