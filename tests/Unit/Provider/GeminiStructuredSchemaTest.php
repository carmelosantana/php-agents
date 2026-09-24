<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// structured() builds `responseSchema` from a schema handed in from outside, so it meets the
// same shapes the tool path does — a `type` that is an array, a nested node needing Gemini's
// upper-case spelling. It went through its own one-line uppercase of the root `type` and
// raised a TypeError on a type array.

/** @return array{GeminiProvider, callable(): array<string, mixed>} */
function geminiStructuredCapture(): array
{
    $payload = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$payload): MockResponse {
        $payload = json_decode((string) $options['body'], true);

        return new MockResponse(
            (string) json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '{}']]], 'finishReason' => 'STOP']]]),
            ['http_code' => 200],
        );
    });

    return [
        new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $client),
        static function () use (&$payload): array {
            return is_array($payload) ? $payload : [];
        },
    ];
}

test('structured normalises a root type array instead of raising a TypeError', function () {
    [$provider, $captured] = geminiStructuredCapture();

    $provider->structured(
        [new UserMessage('hi')],
        (string) json_encode(['type' => ['object', 'null'], 'properties' => ['since' => ['type' => ['string', 'null']]]]),
    );

    expect($captured()['generationConfig']['responseSchema'])->toBe([
        'type' => 'OBJECT',
        'properties' => ['since' => ['type' => 'STRING', 'nullable' => true]],
        'nullable' => true,
    ]);
});

test('structured still defaults a typeless schema to OBJECT and drops name and description', function () {
    [$provider, $captured] = geminiStructuredCapture();

    $provider->structured(
        [new UserMessage('hi')],
        (string) json_encode(['name' => 'answer', 'description' => 'An answer.', 'properties' => ['a' => ['type' => 'string']]]),
    );

    expect($captured()['generationConfig']['responseSchema'])->toBe([
        'properties' => ['a' => ['type' => 'STRING']],
        'type' => 'OBJECT',
    ]);
});
