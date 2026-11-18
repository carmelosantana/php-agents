<?php

declare(strict_types=1);

// Pentest L2 is closed: llama.cpp image paths and URLs go through an ImageResolverInterface.

test('the providers doc no longer lists L2 as open and names ImageResolverInterface', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');

    expect($doc)->not->toContain('known open finding (L2)')
        ->and($doc)->toContain('ImageResolverInterface')
        ->and($doc)->toContain("Every image a resolver returns is checked again against the normalizer's own `ImagePolicy`");
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

test('the local runtime image example imports LlamaCppProvider', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/LOCAL-RUNTIME.md');
    $section = substr($doc, (int) strpos($doc, '## Image Input from PHP'));

    expect($section)->toContain('use CarmeloSantana\PHPAgents\Provider\LlamaCppProvider;');
});

test('the docs say RemoteImageResolver also blocks on DNS and a normalizer takes one resolver', function (string $path) {
    $doc = (string) file_get_contents(__DIR__ . '/../../../' . $path);

    expect($doc)->toContain('which the Gemini image check and `RemoteImageResolver` call, block')
        ->and($doc)->toContain('one `ImageResolverInterface`');
})->with(['docs/PROVIDERS.md', 'docs/adr/0003-provider-request-limits.md']);

test('the LocalImageResolver docblock names the FIFO swap in its residual race', function () {
    $source = (string) file_get_contents(__DIR__ . '/../../../src/Image/LocalImageResolver.php');

    expect($source)->toContain('a FIFO swapped in')
        ->and($source)->toContain('can block');
});

test('the EnvConfig docblock names the other bypasses and what an unserialized copy reads', function () {
    $source = (string) file_get_contents(__DIR__ . '/../../../src/Config/EnvConfig.php');

    expect($source)->toContain('(array) casts')
        ->and($source)->toContain('Reflection')
        ->and($source)->toContain('carries no captured values')
        ->and($source)->toContain('new EnvConfig(');
});
