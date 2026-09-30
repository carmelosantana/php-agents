<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// structured() builds `responseSchema` from a schema handed in from outside, so it meets the
// same shapes the tool path does — a `type` that is an array, a nested node needing Gemini's
// upper-case spelling, and an empty or "0", "1", … keyed `properties` map, which
// json_decode(..., true) makes a PHP list. It went through its own one-line uppercase of the
// root `type` and raised a TypeError on a type array. A body decoded to arrays cannot show
// whether a map went out as a JSON object or a list, so the map tests decode it to objects.

/**
 * A provider whose one request is captured: the second element decodes the body to arrays,
 * the third returns the body as sent, so a test can tell a JSON object from a JSON list.
 *
 * @return array{GeminiProvider, callable(): array<string, mixed>, callable(): string}
 */
function geminiStructuredCapture(): array
{
    $body = '';
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
        $body = (string) $options['body'];

        return new MockResponse(
            (string) json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '{}']]], 'finishReason' => 'STOP']]]),
            ['http_code' => 200],
        );
    });

    return [
        new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $client),
        static function () use (&$body): array {
            $payload = json_decode($body, true);

            return is_array($payload) ? $payload : [];
        },
        static function () use (&$body): string {
            return $body;
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

test('structured sends every properties map as a JSON object', function (string $schema, string $expected) {
    [$provider, , $body] = geminiStructuredCapture();

    $provider->structured([new UserMessage('hi')], $schema);

    expect(json_encode(json_decode($body(), false)->generationConfig->responseSchema))->toBe($expected);
})->with([
    'an empty map' => ['{"type":"object","properties":{}}', '{"type":"OBJECT","properties":{}}'],
    'a map keyed "0", "1"' => ['{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"string"}}}', '{"type":"OBJECT","properties":{"0":{"type":"STRING"},"1":{"type":"STRING"}}}'],
    'an empty map at depth' => ['{"type":"object","properties":{"meta":{"type":"object","properties":{}}}}', '{"type":"OBJECT","properties":{"meta":{"type":"OBJECT","properties":{}}}}'],
    'an empty map under the schema envelope' => ['{"schema":{"type":"object","properties":{}}}', '{"type":"OBJECT","properties":{}}'],
]);
