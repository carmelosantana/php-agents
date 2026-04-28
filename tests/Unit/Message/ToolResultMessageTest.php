<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;

test('tool result message exposes wrapped result while preserving string wire format', function () {
    $result = ToolResult::json(['ok' => true], ['source' => 'test'])->withCallId('call_789');
    $message = new ToolResultMessage($result);

    expect($message->role())->toBe(Role::Tool);
    expect($message->content())->toContain('"ok": true');
    expect($message->toolCallId())->toBe('call_789');
    expect($message->result()->metadata)->toBe(['source' => 'test']);
    expect($message->toArray())->toBe([
        'role' => 'tool',
        'content' => $result->content,
        'tool_call_id' => 'call_789',
    ]);
});