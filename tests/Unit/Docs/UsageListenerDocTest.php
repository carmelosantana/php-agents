<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

test('UsageListenerDoc the README example prints usage for one request', function () {
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');
    expect(preg_match('/^## Usage listener\n[^\x60]*?^\x60\x60\x60php\n(.*?)^\x60\x60\x60$/ms', $readme, $block))->toBe(1);

    $client = new MockHttpClient(new MockResponse(json_encode([
        'model' => 'example-model',
        'choices' => [['message' => ['content' => 'Hello!'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], JSON_THROW_ON_ERROR)));
    $provider = new OpenAICompatibleProvider(model: 'example-model', httpClient: $client);

    ob_start();
    try {
        eval($block[1]);
        $output = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($output)->toBe("chat example-model: 15 tokens\n")
        ->and($client->getRequestsCount())->toBe(1);
});

test('UsageListenerDoc the upgrade guide covers 0.19 and schedules the FFI change for 0.20', function () {
    $upgrading = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');

    expect($upgrading)->toContain("## Upgrading to 0.19\n")
        ->toContain('removed or fixed in 0.20')
        ->not->toContain('removed or fixed in 0.19');
});
