<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('tool result metadata and helper methods are additive and chainable', function () {
    $result = ToolResult::success('ok')
        ->withMetadata(['phase' => 'execute'])
        ->withMimeType('text/plain')
        ->withDisplayHint('plain-text')
        ->withRetryable(false)
        ->withErrorCode('NONE')
        ->withCallId('call_123');

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('ok');
    expect($result->callId)->toBe('call_123');
    expect($result->metadata)->toBe(['phase' => 'execute']);
    expect($result->mimeType)->toBe('text/plain');
    expect($result->displayHint)->toBe('plain-text');
    expect($result->retryable)->toBeFalse();
    expect($result->errorCode)->toBe('NONE');
});

test('tool result json helper emits string content with structured metadata hints', function () {
    $result = ToolResult::json(['id' => 'a1', 'status' => 'done'], ['source' => 'tool']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('"id": "a1"');
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');
    expect($result->metadata)->toBe(['source' => 'tool']);
});

test('tool result withCallId preserves metadata and display fields', function () {
    $result = new ToolResult(
        ToolResultStatus::Error,
        'failure',
        metadata: ['attempt' => 2],
        mimeType: 'text/plain',
        displayHint: 'error',
        retryable: true,
        errorCode: 'TOOL_001',
    );

    $withCallId = $result->withCallId('call_456');

    expect($withCallId->callId)->toBe('call_456');
    expect($withCallId->metadata)->toBe(['attempt' => 2]);
    expect($withCallId->mimeType)->toBe('text/plain');
    expect($withCallId->displayHint)->toBe('error');
    expect($withCallId->retryable)->toBeTrue();
    expect($withCallId->errorCode)->toBe('TOOL_001');
});