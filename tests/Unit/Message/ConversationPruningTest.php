<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

// --- trimToolResults ---

test('trimToolResults leaves short tool results untouched', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('hello'));
    $conversation->add(new AssistantMessage('', [new ToolCall('c1', 'read', ['path' => '/x'])]));
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, 'short content'))->withCallId('c1'),
    ));

    $trimmed = $conversation->trimToolResults(500);

    expect($trimmed->count())->toBe(3);
    $msgs = $trimmed->messages();
    expect($msgs[2]->content())->toBe('short content');
});

test('trimToolResults truncates long tool results', function () {
    $longContent = str_repeat('x', 2000);

    $conversation = new Conversation();
    $conversation->add(new UserMessage('hello'));
    $conversation->add(new AssistantMessage('', [new ToolCall('c1', 'read', [])]));
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, $longContent))->withCallId('c1'),
    ));

    $trimmed = $conversation->trimToolResults(500, 100);

    $msgs = $trimmed->messages();
    $trimmedContent = $msgs[2]->content();
    expect(strlen($trimmedContent))->toBeLessThan(strlen($longContent));
    expect($trimmedContent)->toContain('[... trimmed');
    expect($trimmedContent)->toContain('2000 chars');
});

test('trimToolResults never modifies user or assistant messages', function () {
    $longUserContent = str_repeat('y', 2000);
    $longAssistantContent = str_repeat('z', 2000);

    $conversation = new Conversation();
    $conversation->add(new UserMessage($longUserContent));
    $conversation->add(new AssistantMessage($longAssistantContent));

    $trimmed = $conversation->trimToolResults(500);

    $msgs = $trimmed->messages();
    expect($msgs[0]->content())->toBe($longUserContent);
    expect($msgs[1]->content())->toBe($longAssistantContent);
});

test('trimToolResults preserves tool_call_id', function () {
    $conversation = new Conversation();
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, str_repeat('a', 1000)))->withCallId('call_abc'),
    ));

    $trimmed = $conversation->trimToolResults(200);

    expect($trimmed->messages()[0]->toolCallId())->toBe('call_abc');
});

test('trimToolResults returns new instance', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('hello'));

    $trimmed = $conversation->trimToolResults(500);

    expect($trimmed)->not->toBe($conversation);
});

// --- dropOldestTurns ---

test('dropOldestTurns keeps all when under limit', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    $conversation->add(new UserMessage('u1'));
    $conversation->add(new AssistantMessage('a1'));

    $result = $conversation->dropOldestTurns(5);

    expect($result->count())->toBe(3);
});

test('dropOldestTurns drops oldest turns keeping last N', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    $conversation->add(new UserMessage('u1'));
    $conversation->add(new AssistantMessage('a1'));
    $conversation->add(new UserMessage('u2'));
    $conversation->add(new AssistantMessage('a2'));
    $conversation->add(new UserMessage('u3'));
    $conversation->add(new AssistantMessage('a3'));

    $result = $conversation->dropOldestTurns(2);

    $msgs = $result->messages();
    // System + last 2 user turns with their assistant responses
    expect($msgs[0]->role())->toBe(Role::System);

    $userMessages = array_filter($msgs, fn($m) => $m->role() === Role::User);
    expect(count($userMessages))->toBe(2);

    // First remaining user message should be 'u2'
    $userContentList = array_map(fn($m) => $m->content(), array_values($userMessages));
    expect($userContentList[0])->toBe('u2');
    expect($userContentList[1])->toBe('u3');
});

test('dropOldestTurns always preserves system messages', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    $conversation->add(new UserMessage('u1'));
    $conversation->add(new AssistantMessage('a1'));
    $conversation->add(new UserMessage('u2'));
    $conversation->add(new AssistantMessage('a2'));

    $result = $conversation->dropOldestTurns(1);

    $msgs = $result->messages();
    expect($msgs[0]->role())->toBe(Role::System);
    expect($msgs[0]->content())->toBe('system');
});

test('dropOldestTurns never drops below 1 turn', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    $conversation->add(new UserMessage('u1'));
    $conversation->add(new AssistantMessage('a1'));

    $result = $conversation->dropOldestTurns(0); // clamped to 1

    $userMessages = $result->filter(Role::User);
    expect(count($userMessages))->toBeGreaterThanOrEqual(1);
});

test('dropOldestTurns preserves tool call chains with their turn', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    // Turn 1
    $conversation->add(new UserMessage('u1'));
    $conversation->add(new AssistantMessage('', [new ToolCall('c1', 'read', [])]));
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, 'result1'))->withCallId('c1'),
    ));
    $conversation->add(new AssistantMessage('a1'));
    // Turn 2
    $conversation->add(new UserMessage('u2'));
    $conversation->add(new AssistantMessage('a2'));

    $result = $conversation->dropOldestTurns(1);

    // Should have: system + u2 + a2
    $msgs = $result->messages();
    $userMsgs = array_filter($msgs, fn($m) => $m->role() === Role::User);
    expect(count($userMsgs))->toBe(1);
    $userContent = array_values($userMsgs)[0]->content();
    expect($userContent)->toBe('u2');
});

// --- repairToolPairing ---

test('repairToolPairing removes orphaned tool results', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('hello'));
    // Orphaned tool result — no assistant message with matching tool_call
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, 'orphan'))->withCallId('missing_call'),
    ));
    $conversation->add(new AssistantMessage('response'));

    $repaired = $conversation->repairToolPairing();

    expect($repaired->count())->toBe(2); // user + assistant
    $msgs = $repaired->messages();
    expect($msgs[0]->role())->toBe(Role::User);
    expect($msgs[1]->role())->toBe(Role::Assistant);
});

test('repairToolPairing keeps valid tool result pairs', function () {
    $conversation = new Conversation();
    $conversation->add(new AssistantMessage('', [new ToolCall('c1', 'read', [])]));
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, 'valid result'))->withCallId('c1'),
    ));

    $repaired = $conversation->repairToolPairing();

    expect($repaired->count())->toBe(2);
});

test('repairToolPairing handles mixed valid and orphaned', function () {
    $conversation = new Conversation();
    $conversation->add(new AssistantMessage('', [new ToolCall('c1', 'read', [])]));
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, 'valid'))->withCallId('c1'),
    ));
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, 'orphan'))->withCallId('c_missing'),
    ));

    $repaired = $conversation->repairToolPairing();

    expect($repaired->count())->toBe(2); // assistant + valid tool result
});

// --- fitWithinBudget ---

test('fitWithinBudget returns conversation unchanged when within budget', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    $conversation->add(new UserMessage('hello'));
    $conversation->add(new AssistantMessage('hi'));

    $result = $conversation->fitWithinBudget(100000);

    expect($result->count())->toBe(3);
});

test('fitWithinBudget trims tool results first', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    $conversation->add(new UserMessage('hello'));
    $conversation->add(new AssistantMessage('', [new ToolCall('c1', 'read', [])]));
    // Very large tool result
    $conversation->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, str_repeat('x', 10000)))->withCallId('c1'),
    ));
    $conversation->add(new AssistantMessage('done'));

    $result = $conversation->fitWithinBudget(1000);

    // Tool result should be trimmed
    $toolResults = $result->filter(Role::Tool);
    $toolContent = array_values($toolResults)[0]->content();
    expect(strlen($toolContent))->toBeLessThan(10000);
});

test('fitWithinBudget drops old turns when trimming is not enough', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));

    // Create many turns
    for ($i = 0; $i < 20; $i++) {
        $conversation->add(new UserMessage('user message ' . $i . str_repeat(' ', 200)));
        $conversation->add(new AssistantMessage('assistant response ' . $i . str_repeat(' ', 200)));
    }

    // Budget so small that it can only hold a couple turns
    $result = $conversation->fitWithinBudget(200);

    $userMessages = $result->filter(Role::User);
    expect(count($userMessages))->toBeLessThan(20);
    expect(count($userMessages))->toBeGreaterThanOrEqual(1);
});

// --- clone ---

test('clone creates independent copy', function () {
    $original = new Conversation();
    $original->add(new UserMessage('hello'));

    $cloned = clone $original;
    $cloned->add(new AssistantMessage('hi'));

    expect($original->count())->toBe(1);
    expect($cloned->count())->toBe(2);
});

// --- estimateTokens includes tool calls ---

test('estimateTokens accounts for tool call schemas', function () {
    $withoutTools = new Conversation();
    $withoutTools->add(new AssistantMessage('hello'));

    $withTools = new Conversation();
    $withTools->add(new AssistantMessage('hello', [
        new ToolCall('c1', 'a_tool_name', ['arg1' => 'value1', 'arg2' => 'some longer value']),
    ]));

    expect($withTools->estimateTokens())->toBeGreaterThan($withoutTools->estimateTokens());
});
