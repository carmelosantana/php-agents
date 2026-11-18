<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;

test('provider finish reason cases have the documented values', function () {
    expect(array_map(fn(ProviderFinishReason $case) => $case->value, ProviderFinishReason::cases()))
        ->toBe(['stop', 'tool_use', 'max_tokens', 'error', 'refusal', 'content_filter', 'pause_turn', 'context_window_exceeded']);
});
