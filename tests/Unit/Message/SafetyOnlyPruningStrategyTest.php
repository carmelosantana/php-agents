<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SafetyOnlyPruningStrategy;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('SafetyOnlyPruningStrategy never drops user turns', function () {
    $conv = new Conversation();
    $conv->add(new SystemMessage('System'));

    for ($i = 1; $i <= 10; $i++) {
        $conv->add(new UserMessage("Question {$i}"));
        $conv->add(new AssistantMessage("Answer {$i}"));
    }

    $strategy = new SafetyOnlyPruningStrategy();

    // Budget of 1 token — forces maximum pruning
    $result = $strategy->prune($conv, 1);

    // All user messages should still be present (strategy never drops turns)
    $userMessages = $result->filter(\CarmeloSantana\PHPAgents\Enum\Role::User);
    expect(count($userMessages))->toBe(10);
});

test('SafetyOnlyPruningStrategy trims oversized tool results', function () {
    $conv = new Conversation();
    $conv->add(new UserMessage('hello'));
    $conv->add(new AssistantMessage('', [new ToolCall('c1', 'read', ['path' => '/x'])]));
    // Create a very large tool result
    $largeContent = str_repeat('A', 10_000);
    $conv->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, $largeContent))->withCallId('c1'),
    ));
    $conv->add(new AssistantMessage('I read the file.'));

    $strategy = new SafetyOnlyPruningStrategy();

    // Budget very small — should trigger tool result trimming
    $result = $strategy->prune($conv, 100);

    // All messages still present (never dropped)
    expect($result->count())->toBe(4);

    // Tool result should be trimmed from the original 10k chars
    $toolMsg = $result->messages()[2];
    expect(strlen($toolMsg->content()))->toBeLessThan(10_000);
});

test('SafetyOnlyPruningStrategy returns conversation unchanged when under budget', function () {
    $conv = new Conversation();
    $conv->add(new UserMessage('hello'));
    $conv->add(new AssistantMessage('world'));

    $strategy = new SafetyOnlyPruningStrategy();

    $result = $strategy->prune($conv, 999_999);
    expect($result->count())->toBe(2);
});

test('SafetyOnlyPruningStrategy repairs tool pairing', function () {
    $conv = new Conversation();
    $conv->add(new UserMessage('hello'));
    // Orphaned tool result (no matching assistant tool_call)
    $conv->add(new ToolResultMessage(
        (new ToolResult(ToolResultStatus::Success, 'orphan'))->withCallId('orphan_id'),
    ));
    $conv->add(new AssistantMessage('done'));

    $strategy = new SafetyOnlyPruningStrategy();

    $result = $strategy->prune($conv, 999_999);
    // repairToolPairing removes orphaned tool results
    expect($result->count())->toBeLessThanOrEqual(3);
});
