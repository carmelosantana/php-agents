<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\Conversation;

// Images in the token estimate, and image checks without ext-fileinfo.

test('the agents doc says an image part counts as IMAGE_TOKENS', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect($doc)->toContain('counts each image part as `Conversation::IMAGE_TOKENS` (' . number_format(Conversation::IMAGE_TOKENS) . ')');
});

test('the upgrade guide records both image runtime changes', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/UPGRADING.md');

    expect($doc)->toContain('works without ext-fileinfo')
        ->and($doc)->toContain('counts as `Conversation::IMAGE_TOKENS` (1,000) instead of one token per 4 bytes');
});
