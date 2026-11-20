<?php

declare(strict_types=1);

// UntrustedText (finish telemetry on every run, provider and tool error text) calls mb_scrub(),
// mb_strlen() and mb_substr(), so mbstring is a runtime requirement, declared as the extension
// itself rather than a polyfill dependency.

test('composer.json requires ext-mbstring at runtime', function () {
    /** @var array{require?: array<string, string>, 'require-dev'?: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'] ?? [])->toHaveKey('ext-mbstring')
        ->and($composer['require']['ext-mbstring'] ?? null)->toBe('*');
});
