<?php

declare(strict_types=1);

// Pentest L2 is closed: llama.cpp image paths and URLs go through an ImageResolverInterface.

test('the providers doc no longer lists L2 as open and names ImageResolverInterface', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');

    expect($doc)->not->toContain('known open finding (L2)')
        ->and($doc)->toContain('ImageResolverInterface');
});

test('the request limits ADR no longer calls L2 open', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/adr/0003-provider-request-limits.md');

    expect($doc)->not->toContain('open finding')
        ->and($doc)->toContain('ImageResolverInterface');
});

test('the local runtime doc shows LocalImageResolver for image input', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/LOCAL-RUNTIME.md');

    expect($doc)->toContain('LocalImageResolver')
        ->and($doc)->toContain('UserMessage::withImages(');
});
