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
        ->and($doc)->toContain("'Provider paused the turn (pause_turn); server-tool continuation is not supported.'");
});
