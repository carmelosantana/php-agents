<?php

declare(strict_types=1);

// docs/PROVIDERS.md describes how Anthropic thinking blocks are round-tripped.
// This pins the sentences, so a change to when blocks are dropped fails here
// until the docs follow.

test('the Anthropic section says thinking blocks are sent back unchanged within a run', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');

    expect($doc)->toContain('Thinking blocks are kept on the AssistantMessage and sent back unchanged within a run')
        ->and($doc)->toContain('signature_delta')
        ->and($doc)->toContain('when pruning changes the conversation')
        ->and($doc)->toContain('when a new `run()` starts');
});
