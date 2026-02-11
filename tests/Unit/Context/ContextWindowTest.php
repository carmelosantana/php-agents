<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Provider\Usage;

test('context window reports correct defaults', function () {
    $ctx = new ContextWindow();

    expect($ctx->maxTokens())->toBe(128000);
    expect($ctx->reservedTokens())->toBe(4096);
    expect($ctx->usedTokens())->toBe(0);
});

test('availableTokens subtracts used and reserved', function () {
    $ctx = new ContextWindow(maxTok: 10000, reservedTok: 1000);
    $ctx->estimate(3000);

    expect($ctx->availableTokens())->toBe(6000);
});

test('usagePercent calculates correctly', function () {
    $ctx = new ContextWindow(maxTok: 10000, reservedTok: 0);
    $ctx->estimate(5000);

    expect($ctx->usagePercent())->toBe(50.0);
});

test('report sets used tokens from Usage', function () {
    $ctx = new ContextWindow();
    $ctx->report(new Usage(promptTokens: 100, completionTokens: 50, totalTokens: 150));

    expect($ctx->usedTokens())->toBe(150);
});

test('isWarning triggers at threshold', function () {
    $ctx = new ContextWindow(maxTok: 1000, reservedTok: 0, warningThreshold: 80.0);
    $ctx->estimate(800);

    expect($ctx->isWarning())->toBeTrue();
});

test('isCritical triggers at threshold', function () {
    $ctx = new ContextWindow(maxTok: 1000, reservedTok: 0, criticalThreshold: 95.0);
    $ctx->estimate(950);

    expect($ctx->isCritical())->toBeTrue();
});

test('reset clears used tokens', function () {
    $ctx = new ContextWindow();
    $ctx->estimate(5000);
    $ctx->reset();

    expect($ctx->usedTokens())->toBe(0);
});

test('toArray returns all fields', function () {
    $ctx = new ContextWindow(maxTok: 10000, reservedTok: 1000);
    $array = $ctx->toArray();

    expect($array)->toHaveKeys(['max', 'used', 'reserved', 'available', 'percent']);
    expect($array['max'])->toBe(10000);
    expect($array['reserved'])->toBe(1000);
});
