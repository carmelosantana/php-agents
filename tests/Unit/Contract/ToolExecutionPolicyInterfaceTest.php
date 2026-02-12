<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;

test('interface has shouldExecute method', function () {
    $reflection = new ReflectionClass(ToolExecutionPolicyInterface::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('shouldExecute'))->toBeTrue();

    $method = $reflection->getMethod('shouldExecute');
    expect($method->getNumberOfRequiredParameters())->toBe(2);
});
