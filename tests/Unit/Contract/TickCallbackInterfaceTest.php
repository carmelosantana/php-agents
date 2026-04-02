<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;

test('interface has tick method', function () {
    $reflection = new ReflectionClass(TickCallbackInterface::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('tick'))->toBeTrue();

    $method = $reflection->getMethod('tick');
    expect($method->getNumberOfRequiredParameters())->toBe(0);
    expect($method->getReturnType()?->getName())->toBe('void');
});
