<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\NullTickCallback;
use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;

test('implements TickCallbackInterface', function () {
    $callback = new NullTickCallback();

    expect($callback)->toBeInstanceOf(TickCallbackInterface::class);
});

test('tick does nothing and does not throw', function () {
    $callback = new NullTickCallback();

    // Should not throw — just a no-op
    $callback->tick();
    $callback->tick();
    $callback->tick();

    expect(true)->toBeTrue();
});
