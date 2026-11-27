<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Server\Tool;
use CarmeloSantana\PHPAgents\Mcp\Server\ToolRegistry;

// Mutation: remove the duplicate check from add().
it('rejects a second tool with the same name', function (): void {
    $tool = new Tool('task_read', 'Read a task.', ['type' => 'object'], static fn () => null);
    $registry = new ToolRegistry();
    $registry->add($tool);

    expect(fn () => $registry->add($tool))->toThrow(InvalidArgumentException::class);
});

// Mutation: return every tool from forProfile().
it('keeps only tools in the requested profile', function (): void {
    $core = new Tool('task_read', 'Read a task.', ['type' => 'object'], static fn () => null, profiles: ['full', 'core']);
    $full = new Tool('task_write', 'Write a task.', ['type' => 'object'], static fn () => null, profiles: ['full']);
    $registry = new ToolRegistry();
    $registry->add($core);
    $registry->add($full);

    expect($registry->forProfile('core')->list())->toBe([$core])
        ->and($registry->forProfile('missing')->list())->toBe([])
        ->and($registry->list())->toBe([$core, $full]);
});

// Mutation: include alias entries in list().
it('resolves aliases by name and lists each canonical tool once', function (): void {
    $tool = new Tool('task_read', 'Read a task.', ['type' => 'object'], static fn () => null, profiles: ['core']);
    $registry = new ToolRegistry();
    $registry->add($tool);
    $registry->alias('get_task', 'task_read');

    expect($registry->find('get_task'))->toBe($tool)
        ->and($registry->find('task_read'))->toBe($tool)
        ->and($registry->list())->toBe([$tool])
        ->and($registry->forProfile('core')->find('get_task'))->toBe($tool)
        ->and($registry->forProfile('admin')->find('get_task'))->toBeNull();
});

// Mutation: allow an alias and a canonical tool to claim the same name.
it('keeps alias and canonical names unique', function (): void {
    $tool = new Tool('task_read', 'Read a task.', ['type' => 'object'], static fn () => null);
    $registry = new ToolRegistry();
    $registry->add($tool);
    $registry->alias('get_task', 'task_read');

    expect(fn () => $registry->alias('task_read', 'task_read'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->alias('get_task', 'task_read'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->add(new Tool('get_task', 'Get a task.', ['type' => 'object'], static fn () => null)))
        ->toThrow(InvalidArgumentException::class);
});

// Mutation: allow aliases to point to unregistered tools.
it('requires a registered canonical target for an alias', function (): void {
    $registry = new ToolRegistry();

    expect(fn () => $registry->alias('get_task', 'task_read'))->toThrow(InvalidArgumentException::class);
});

// Mutation: emit outputSchema with a null value.
it('emits tool definitions with an output schema only when set', function (): void {
    $input = ['type' => 'object', 'required' => ['id']];
    $output = ['type' => 'object'];
    $annotations = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true];
    $withoutOutput = new Tool('task_read', 'Read a task.', $input, static fn () => null, annotations: $annotations);
    $withOutput = new Tool('task_read', 'Read a task.', $input, static fn () => null, outputSchema: $output, annotations: $annotations);
    $definition = [
        'name' => 'task_read',
        'description' => 'Read a task.',
        'inputSchema' => $input,
        'annotations' => $annotations,
    ];

    expect($withoutOutput->definition())->toBe($definition)
        ->and($withOutput->definition())->toBe($definition + ['outputSchema' => $output]);
});

// Mutation: set anthropic/alwaysLoad on every tool.
it('sets the always-load hint only for hot tools', function (): void {
    $regular = new Tool('task_read', 'Read a task.', ['type' => 'object'], static fn () => null);
    $hot = new Tool('task_read', 'Read a task.', ['type' => 'object'], static fn () => null, hot: true);

    expect($regular->definition())->not->toHaveKey('_meta')
        ->and($hot->definition()['_meta'])->toBe(['anthropic/alwaysLoad' => true]);
});
