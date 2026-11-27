<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\StructuredStreaming;
use CarmeloSantana\PHPAgents\Provider\CliProvider;
use Tests\Support\Docs\SnippetRunner;

// The structured streaming docs show a structuredEvents() loop. These run it against a mocked
// stream and check that the upgrade guide and feature matrix name the API.

$root = dirname(__DIR__, 3);

/** The PHP code block in the "## Structured streaming" section of PROVIDERS.md. */
function structuredStreamingDocBlock(string $root): string
{
    $doc = (string) file_get_contents($root . '/docs/PROVIDERS.md');
    preg_match('/^## Structured streaming\n.*?^```php\n(.*?)^```$/ms', $doc, $m);

    return $m[1] ?? '';
}

test('the structured streaming example prints the document as it arrives and decodes it', function () use ($root) {
    $code = structuredStreamingDocBlock($root);
    expect($code)->toContain('->structuredEvents($messages, $schema)');

    $result = json_decode(SnippetRunner::run(<<<'PHP'
        <?php
        $chunk = fn(array $delta, ?string $finish = null): string => 'data: ' . json_encode(['model' => 'example-model', 'choices' => [['delta' => $delta, 'finish_reason' => $finish]]]) . "\n\n";
        $client = new \Symfony\Component\HttpClient\MockHttpClient(new \Symfony\Component\HttpClient\Response\MockResponse(
            $chunk(['content' => '{"title":']) . $chunk(['content' => '"Ship 0.21"}']) . $chunk([], 'stop') . "data: [DONE]\n\n",
        ));
        $provider = new \CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider(model: 'example-model', httpClient: $client);
        ob_start();

        PHP . $code . <<<'PHP'

        $output = (string) ob_get_clean();
        echo json_encode(['output' => $output, 'document' => $document, 'requests' => $client->getRequestsCount()], JSON_THROW_ON_ERROR);
        PHP)->output(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['output'])->toBe('{"title":"Ship 0.21"}')
        ->and($result['document'])->toBe(['title' => 'Ship 0.21'])
        ->and($result['requests'])->toBe(1);
});

test('the example falls back to structured() for a provider without StructuredStreaming', function () use ($root) {
    expect(is_subclass_of(CliProvider::class, StructuredStreaming::class))->toBeFalse()
        ->and(structuredStreamingDocBlock($root))->toContain('StructuredOutput::extract($provider, $messages, $schema)');
});

test('the upgrade guide and the feature matrix cover structured streaming', function () use ($root) {
    $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
    $providers = (string) file_get_contents($root . '/docs/PROVIDERS.md');

    expect($upgrading)->toContain("## Upgrading to 0.21.0\n")
        ->and($upgrading)->toContain('`Contract\StructuredStreaming`')
        ->and($providers)->toContain('| `structuredEvents()` ([Structured streaming](#structured-streaming)) |')
        ->and($providers)->toContain('`structured_stream`');
});
