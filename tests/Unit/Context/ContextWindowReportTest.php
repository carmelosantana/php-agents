<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Provider\Usage;

// report() used to overwrite the local estimate, so a provider that under-reported usage (or a
// proxy that zeroed it) hid how full the window was. The higher figure now wins.

test('provider under-reporting does not lower the local estimate', function () {
    $window = new ContextWindow(maxTok: 1_000, reservedTok: 0);
    $window->estimate(900);

    $window->report(new Usage(totalTokens: 100));

    expect($window->usedTokens())->toBe(900)
        ->and($window->usagePercent())->toBeGreaterThanOrEqual(90.0);
});

test('a higher report still wins', function () {
    $window = new ContextWindow(maxTok: 1_000, reservedTok: 0);
    $window->estimate(300);

    $window->report(new Usage(totalTokens: 700));

    expect($window->usedTokens())->toBe(700);
});

test('reset clears the estimate', function () {
    $window = new ContextWindow(maxTok: 1_000, reservedTok: 0);
    $window->estimate(900);
    $window->reset();

    $window->report(new Usage(totalTokens: 100));

    expect($window->usedTokens())->toBe(100);
});

test('a new estimate replaces the previous one', function () {
    $window = new ContextWindow(maxTok: 1_000, reservedTok: 0);
    $window->estimate(900);
    $window->estimate(200);

    $window->report(new Usage(totalTokens: 100));

    expect($window->usedTokens())->toBe(200);
});
