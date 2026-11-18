<?php

declare(strict_types=1);

// Provider, tool and MCP error text is redacted and bounded. These pin the documented bounds,
// the never-echoed raw body, and the limit of pattern-based redaction.

test('the agents guide documents error redaction and its bounds', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect($doc)->toContain('### Error text is redacted and bounded')
        ->and($doc)->toContain('UntrustedText::forDisplay()')
        ->and($doc)->toContain('cut to 300 characters')
        ->and($doc)->toContain('first 64 KiB of the body')
        ->and($doc)->toContain('is never echoed')
        ->and($doc)->toContain('is cut to 500 characters')
        ->and($doc)->toContain('Successful tool content is the tool\'s own data and is not rewritten.')
        ->and($doc)->toContain('An opaque credential that matches none of these shapes cannot be redacted');
});

test('the tools guide says MCP results and metadata are redacted', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/TOOLS-AND-TOOLKITS.md');

    expect($doc)->toContain('Results are redacted too.')
        ->and($doc)->toContain("\$result->metadata['mcp']")
        ->and($doc)->toContain('cannot be redacted.');
});
