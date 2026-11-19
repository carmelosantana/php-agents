<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;

// docs/AGENTS.md lists every AgentFinishReason and the Output::$providerFinishReason property.
// This pins both, so a new case or a renamed property fails here until the docs follow.

test('the finish reasons table lists every AgentFinishReason case', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    foreach (AgentFinishReason::cases() as $case) {
        expect($doc)->toContain('| `AgentFinishReason::' . $case->name . '` |');
    }
});

test('the Output section documents providerFinishReason', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect(property_exists(Output::class, 'providerFinishReason'))->toBeTrue()
        ->and($doc)->toContain('$output->providerFinishReason;')
        ->and($doc)->toContain("'Provider error: the response ended without a valid finish reason.'")
        ->and($doc)->toContain("'Provider paused the turn (pause_turn); server-tool continuation is not supported.'")
        ->and($doc)->toContain("| `MaxTokens` with no text | `AgentFinishReason::Error` with content `'Provider stopped at max_tokens before producing an answer; raise max_tokens in providerOptions.'`");
});

test('AGENTS.md says the first failure reason of a turn is sticky', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect($doc)->not->toContain("The run's accumulated reason is the last chunk reason that is not `Stop`;")
        ->and($doc)->toContain('The first failure reason a chunk carries (`Error`, `Refusal`, `ContentFilter`, `ContextWindowExceeded`, `PauseTurn` or `MaxTokens`) is the turn\'s reason: no later chunk changes it to `ToolUse` or `Stop`, and every tool call of the turn is dropped.');
});

test('AGENTS.md documents how a cancel ends the run', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect($doc)->not->toContain('The cancellation check happens at the top of each iteration')
        ->and($doc)->toContain("after `agent.error` with `'Task cancelled'`")
        ->and($doc)->toContain("`content` is `'Task was cancelled.'`; the provider is not called")
        ->and($doc)->toContain('each started call gets `agent.tool_input_discarded` with reason `cancelled`')
        ->and($doc)->toContain("Every call that did not start gets an error `ToolResult` with content `'Cancelled before it ran.'` and `errorCode` `'cancelled'`");
});
