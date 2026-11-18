<?php

declare(strict_types=1);

// docs/PROVIDERS.md lists the request fields caller options cannot replace, and docs/AGENTS.md
// says providerOptions cannot replace the agent's instructions or tools. The provider tests
// prove the behaviour; this pins the wording so the docs keep naming every reserved field.

test('PROVIDERS.md lists the reserved request fields of each provider', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');

    expect($doc)->toContain('## Caller Options')
        ->and($doc)->toContain('| `AnthropicProvider` | `messages`, `stream` | `system` | `tools` |')
        ->and($doc)->toContain('| `GeminiProvider` | `contents` | `systemInstruction` | `tools` |')
        ->and($doc)->toContain('| `OpenAICompatibleProvider` and its subclasses | `messages`, `stream` | (inside `messages`) | `tools` |')
        ->and($doc)->toContain('| `OpenAIResponsesProvider` | `input`, `stream` | (inside `input`) | `tools` |');
});

test('AGENTS.md says providerOptions cannot replace the instructions or the tools', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/AGENTS.md');

    expect($doc)->toContain("They cannot replace the agent's instructions or its tools either");
});
