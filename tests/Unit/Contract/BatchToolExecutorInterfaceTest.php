<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('BatchToolExecutorInterface is an interface', function () {
    $reflection = new ReflectionClass(BatchToolExecutorInterface::class);

    expect($reflection->isInterface())->toBeTrue();
});

test('BatchToolExecutorInterface extends ToolExecutorInterface', function () {
    $reflection = new ReflectionClass(BatchToolExecutorInterface::class);

    expect($reflection->implementsInterface(ToolExecutorInterface::class))->toBeTrue();
});

test('BatchToolExecutorInterface has executeBatch method', function () {
    $reflection = new ReflectionClass(BatchToolExecutorInterface::class);

    expect($reflection->hasMethod('executeBatch'))->toBeTrue();
});

test('executeBatch has one required parameter', function () {
    $reflection = new ReflectionClass(BatchToolExecutorInterface::class);
    $method = $reflection->getMethod('executeBatch');

    expect($method->getNumberOfRequiredParameters())->toBe(1);
});

test('executeBatch return type is array', function () {
    $reflection = new ReflectionClass(BatchToolExecutorInterface::class);
    $method = $reflection->getMethod('executeBatch');

    expect($method->getReturnType()?->getName())->toBe('array');
});
