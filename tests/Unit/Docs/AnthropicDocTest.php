<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use Symfony\Component\HttpClient\MockHttpClient;

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

// The static fallback table and the default model are generated from the provider here, so a
// changed fallback or default fails until docs/PROVIDERS.md follows.

test('the Anthropic fallback table matches the static fallback models', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');
    $client = new MockHttpClient(fn() => throw new RuntimeException('offline'));
    $models = (new AnthropicProvider(apiKey: 'test', httpClient: $client))->models();
    $tokens = fn(int $n): string => $n % 1_000_000 === 0 ? ($n / 1_000_000) . 'M' : ($n / 1000) . 'K';

    expect($models)->not->toBeEmpty();
    foreach ($models as $model) {
        expect($model->metadataSource)->toBe('static-fallback')
            ->and($doc)->toContain(sprintf(
                '| `%s` | %s | %s |',
                $model->id,
                $tokens($model->contextWindow),
                number_format($model->maxTokens),
            ));
    }
});

test('the docs name no retired Anthropic model', function (string $path) {
    $doc = (string) file_get_contents(__DIR__ . '/../../../' . $path);

    expect($doc)->not->toContain('claude-sonnet-4-20250514')
        ->and($doc)->not->toContain('claude-opus-4-20250514')
        ->and($doc)->not->toContain('claude-3-');
})->with(['docs/PROVIDERS.md', 'docs/GETTING-STARTED.md', 'README.md']);

test('the documented default model is the constructor default', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');
    $model = (new ReflectionMethod(AnthropicProvider::class, '__construct'))->getParameters()[0];

    expect($model->getName())->toBe('model')
        ->and($doc)->toContain(sprintf("| `model` | `string` | `'%s'` |", $model->getDefaultValue()));
});

// structured() uses native structured outputs: a forced tool_choice returns a 400 on current models.

test('the Anthropic structured output section describes native structured outputs', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');
    $anthropic = substr($doc, (int) strpos($doc, '## AnthropicProvider'));
    $start = (int) strpos($anthropic, '### Structured Output');
    $section = substr($anthropic, $start, (int) strpos($anthropic, '### Model List') - $start);

    expect($section)->toContain('native structured outputs')
        ->and($section)->toContain("`output_config: {format: {type: 'json_schema', schema: ...}}`")
        ->and($section)->toContain('`additionalProperties: false`')
        ->and($section)->not->toContain('tool_choice')
        ->and($section)->not->toContain('tool-use trick');
});

test('the Anthropic structured output section describes the schema preparation and the fallback path', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');
    $anthropic = substr($doc, (int) strpos($doc, '## AnthropicProvider'));
    $start = (int) strpos($anthropic, '### Structured Output');
    $section = substr($anthropic, $start, (int) strpos($anthropic, '### Model List') - $start);
    $stripped = (new ReflectionClassConstant(AnthropicProvider::class, 'UNSUPPORTED_SCHEMA_KEYWORDS'))->getValue();
    $keywords = array_map(fn(string $k): string => '`' . $k . '`', $stripped);
    $last = array_pop($keywords);

    expect($section)->toContain("an envelope's `name` and `description` are not")
        ->and($section)->toContain('keeps `required` exactly as you wrote it (optional properties stay optional and no `null` type is added)')
        ->and($section)->toContain('strips the keywords the API rejects: ' . implode(', ', $keywords) . ' and ' . $last . '.')
        ->and($section)->toContain('falls back to best-effort JSON')
        ->and($section)->toContain('`Respond with only a JSON value that matches this JSON Schema, with no other text:`')
        ->and($section)->not->toContain('nullable');
});
