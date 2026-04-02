<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('interface has execute method', function () {
    $reflection = new ReflectionClass(ToolExecutorInterface::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('execute'))->toBeTrue();

    $method = $reflection->getMethod('execute');
    expect($method->getNumberOfRequiredParameters())->toBe(2);

    $params = $method->getParameters();
    expect($params[0]->getType()?->getName())->toBe(ToolInterface::class);
    expect($params[1]->getType()?->getName())->toBe('array');
    expect($method->getReturnType()?->getName())->toBe(ToolResult::class);
});
