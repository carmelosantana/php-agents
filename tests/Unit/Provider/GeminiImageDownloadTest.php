<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Sends one UserMessage holding $imageUrl through GeminiProvider::chat() against a
 * MockHttpClient. $images maps a GET URL to the MockResponse it answers with; any other
 * GET answers 404. Returns the GET URLs asked for, their options, and the image part of
 * the generateContent body.
 *
 * @param array<string, MockResponse> $images
 * @return array{gets: list<string>, options: list<array<string, mixed>>, part: array<string, mixed>}
 */
function geminiImageDownload(string $imageUrl, array $images = []): array
{
    $gets = [];
    $getOptions = [];
    $payload = null;

    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$gets, &$getOptions, &$payload, $images): MockResponse {
        if ($method === 'GET') {
            $gets[] = $url;
            $getOptions[] = $options;

            return $images[$url] ?? new MockResponse('', ['http_code' => 404]);
        }
        $payload = json_decode($options['body'], true);

        return new MockResponse((string) json_encode([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'ok']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        ]), ['http_code' => 200]);
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $client);
    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
        ]),
    ]);

    return ['gets' => $gets, 'options' => $getOptions, 'part' => $payload['contents'][0]['parts'][0]];
}

function pngResponse(): MockResponse
{
    return new MockResponse("\x89PNG", ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
}

function redirectTo(string $location): MockResponse
{
    return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $location]]);
}

test('a private address is never requested', function (string $url) {
    $result = geminiImageDownload($url, [$url => pngResponse()]);

    expect($result['gets'])->toBe([])
        ->and($result['part'])->not->toHaveKey('inlineData')
        ->and($result['part']['text'])->toContain('[Image: could not download');
})->with([
    'loopback' => 'http://127.0.0.1/a.png',
    'metadata' => 'http://169.254.169.254/latest/meta-data/',
    'rfc1918' => 'http://10.0.0.5/a.png',
    'ipv6 loopback' => 'http://[::1]/a.png',
]);

test('localhost resolves to a refused address', function () {
    $result = geminiImageDownload('http://localhost/a.png', ['http://localhost/a.png' => pngResponse()]);

    expect($result['gets'])->toBe([])
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('a public image is inlined', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', ['http://93.184.216.34/a.png' => pngResponse()]);

    expect($result['part'])->toBe([
        'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode("\x89PNG")],
    ]);
});

test('the checked IP is pinned via resolve', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', ['http://93.184.216.34/a.png' => pngResponse()]);

    expect($result['options'][0]['resolve'])->toBe(['93.184.216.34' => '93.184.216.34'])
        ->and($result['options'][0]['max_redirects'])->toBe(0);
});

test('a non-image content type is refused', function () {
    $html = new MockResponse('<html></html>', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]);
    $result = geminiImageDownload('http://93.184.216.34/a.png', ['http://93.184.216.34/a.png' => $html]);

    expect($result['part'])->not->toHaveKey('inlineData')
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('an image over 20 MiB is refused', function () {
    $big = new MockResponse(str_repeat('a', 20_971_521), ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
    $result = geminiImageDownload('http://93.184.216.34/a.png', ['http://93.184.216.34/a.png' => $big]);

    expect($result['part'])->not->toHaveKey('inlineData')
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('a redirect to a private address is not followed', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', [
        'http://93.184.216.34/a.png' => redirectTo('http://169.254.169.254/latest/meta-data/'),
        'http://169.254.169.254/latest/meta-data/' => pngResponse(),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/a.png'])
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('a redirect to a public image is followed', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', [
        'http://93.184.216.34/a.png' => redirectTo('http://93.184.216.35/b.png'),
        'http://93.184.216.35/b.png' => pngResponse(),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/a.png', 'http://93.184.216.35/b.png'])
        ->and($result['options'][1]['resolve'])->toBe(['93.184.216.35' => '93.184.216.35'])
        ->and($result['part'])->toBe([
            'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode("\x89PNG")],
        ]);
});

test('a relative redirect is resolved against the current URL', function () {
    $result = geminiImageDownload('http://93.184.216.34/dir/a.png', [
        'http://93.184.216.34/dir/a.png' => redirectTo('b.png'),
        'http://93.184.216.34/dir/b.png' => pngResponse(),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/dir/a.png', 'http://93.184.216.34/dir/b.png'])
        ->and($result['part'])->toHaveKey('inlineData');
});

test('three redirects are followed', function () {
    $result = geminiImageDownload('http://93.184.216.34/0.png', [
        'http://93.184.216.34/0.png' => redirectTo('/1.png'),
        'http://93.184.216.34/1.png' => redirectTo('/2.png'),
        'http://93.184.216.34/2.png' => redirectTo('/3.png'),
        'http://93.184.216.34/3.png' => pngResponse(),
    ]);

    expect($result['part'])->toHaveKey('inlineData');
});

test('a fourth redirect is refused', function () {
    $result = geminiImageDownload('http://93.184.216.34/0.png', [
        'http://93.184.216.34/0.png' => redirectTo('/1.png'),
        'http://93.184.216.34/1.png' => redirectTo('/2.png'),
        'http://93.184.216.34/2.png' => redirectTo('/3.png'),
        'http://93.184.216.34/3.png' => redirectTo('/4.png'),
        'http://93.184.216.34/4.png' => pngResponse(),
    ]);

    expect($result['gets'])->toHaveCount(4)
        ->and($result['gets'])->not->toContain('http://93.184.216.34/4.png')
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('a redirect to a non-http scheme is refused', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', [
        'http://93.184.216.34/a.png' => redirectTo('file:///etc/passwd'),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/a.png'])
        ->and($result['part']['text'])->toContain('[Image: could not download');
});
