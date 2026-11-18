<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Sends one UserMessage holding $imageUrl through GeminiProvider::chat(). The API client
 * only answers generateContent and fails the test on any GET; image GETs go to a separate
 * MockHttpClient injected as imageHttpClient. $images maps a GET URL to the MockResponse it
 * answers with; any other GET answers 404. Returns the GET URLs asked for, their options,
 * and the image part of the generateContent body. $hostResolver, when given, replaces the
 * provider's DNS lookup (private property, set by reflection) so host names need no real DNS.
 *
 * @param array<string, MockResponse> $images
 * @param (\Closure(string): list<string>)|null $hostResolver
 * @return array{gets: list<string>, options: list<array<string, mixed>>, part: array<string, mixed>}
 */
function geminiImageDownload(string $imageUrl, array $images = [], ?\Closure $hostResolver = null): array
{
    $gets = [];
    $getOptions = [];
    $payload = null;

    $imageClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$gets, &$getOptions, $images): MockResponse {
        $gets[] = $url;
        $getOptions[] = $options;

        return $images[$url] ?? new MockResponse('', ['http_code' => 404]);
    });

    $apiClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$payload): MockResponse {
        if ($method === 'GET') {
            throw new \LogicException("The API client was asked for {$url}");
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

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $apiClient, imageHttpClient: $imageClient);
    if ($hostResolver !== null) {
        (new ReflectionProperty(GeminiProvider::class, 'hostResolver'))->setValue($provider, $hostResolver);
    }
    $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
        ]),
    ]);

    return ['gets' => $gets, 'options' => $getOptions, 'part' => $payload['contents'][0]['parts'][0]];
}

function geminiImagePngResponse(): MockResponse
{
    return new MockResponse("\x89PNG", ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
}

function geminiImageRedirectTo(string $location): MockResponse
{
    return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $location]]);
}

test('a private address is never requested', function (string $url) {
    $result = geminiImageDownload($url, [$url => geminiImagePngResponse()]);

    expect($result['gets'])->toBe([])
        ->and($result['part'])->not->toHaveKey('inlineData')
        ->and($result['part']['text'])->toContain('[Image: could not download');
})->with([
    'loopback' => 'http://127.0.0.1/a.png',
    'metadata' => 'http://169.254.169.254/latest/meta-data/',
    'rfc1918' => 'http://10.0.0.5/a.png',
    'ipv6 loopback' => 'http://[::1]/a.png',
]);

test('an IPv6 address PHP calls global but that reaches a private network is refused', function (string $ip) {
    $url = "http://[{$ip}]/a.png";
    $result = geminiImageDownload($url, [$url => geminiImagePngResponse()]);

    expect($result['gets'])->toBe([])
        ->and($result['part']['text'])->toContain('[Image: could not download');
})->with([
    'NAT64 well-known /96' => '64:ff9b::7f00:1',
    'NAT64 local-use /48' => '64:ff9b:1::a9fe:a9fe',
    'site-local fec0::/10' => 'fec0::1',
    'multicast ff00::/8' => 'ff02::1',
    'IPv4-translated ::ffff:0:0/96' => '::ffff:0:7f00:1',
]);

test('a public IPv6 address is still accepted by the deny-list', function () {
    $url = 'http://[2606:4700::1111]/a.png';
    $result = geminiImageDownload($url, [$url => geminiImagePngResponse()]);

    expect($result['gets'])->toBe([$url]);
});

test('a public IPv6-literal image sends no resolve pin and is inlined', function () {
    $url = 'http://[2606:4700::1111]/a.png';
    $result = geminiImageDownload($url, [$url => geminiImagePngResponse()]);

    expect($result['options'][0]['resolve'] ?? [])->toBe([])
        ->and($result['part'])->toBe([
            'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode("\x89PNG")],
        ]);
});

test('localhost resolves to a refused address', function () {
    $result = geminiImageDownload('http://localhost/a.png', ['http://localhost/a.png' => geminiImagePngResponse()]);

    expect($result['gets'])->toBe([])
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('a public image is inlined', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', ['http://93.184.216.34/a.png' => geminiImagePngResponse()]);

    expect($result['part'])->toBe([
        'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode("\x89PNG")],
    ]);
});

test('an IPv4-literal host sends no resolve pin and no redirect budget', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', ['http://93.184.216.34/a.png' => geminiImagePngResponse()]);

    expect($result['options'][0]['resolve'] ?? [])->toBe([])
        ->and($result['options'][0]['max_redirects'])->toBe(0);
});

test('a host name is pinned to the address it resolved to', function () {
    $result = geminiImageDownload(
        'https://img.test/a.png',
        ['https://img.test/a.png' => geminiImagePngResponse()],
        fn(string $host): array => $host === 'img.test' ? ['93.184.216.34'] : [],
    );

    expect($result['gets'])->toBe(['https://img.test/a.png'])
        ->and($result['options'][0]['resolve'])->toBe(['img.test' => '93.184.216.34'])
        ->and($result['part'])->toHaveKey('inlineData');
});

test('a redirect to another host name is pinned to that host\'s address', function () {
    $result = geminiImageDownload(
        'https://img.test/a.png',
        [
            'https://img.test/a.png' => geminiImageRedirectTo('https://cdn.test/b.png'),
            'https://cdn.test/b.png' => geminiImagePngResponse(),
        ],
        fn(string $host): array => match ($host) {
            'img.test' => ['93.184.216.34'],
            'cdn.test' => ['93.184.216.35'],
            default => [],
        },
    );

    expect($result['gets'])->toBe(['https://img.test/a.png', 'https://cdn.test/b.png'])
        ->and($result['options'][1]['resolve'])->toBe(['cdn.test' => '93.184.216.35'])
        ->and($result['part'])->toHaveKey('inlineData');
});

test('a host name that resolves to a private address is never requested', function () {
    $result = geminiImageDownload(
        'https://img.test/a.png',
        ['https://img.test/a.png' => geminiImagePngResponse()],
        fn(string $host): array => ['10.0.0.1'],
    );

    expect($result['gets'])->toBe([])
        ->and($result['part']['text'])->toContain('[Image: could not download');
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
        'http://93.184.216.34/a.png' => geminiImageRedirectTo('http://169.254.169.254/latest/meta-data/'),
        'http://169.254.169.254/latest/meta-data/' => geminiImagePngResponse(),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/a.png'])
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('a redirect to a public image is followed', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', [
        'http://93.184.216.34/a.png' => geminiImageRedirectTo('http://93.184.216.35/b.png'),
        'http://93.184.216.35/b.png' => geminiImagePngResponse(),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/a.png', 'http://93.184.216.35/b.png'])
        ->and($result['options'][1]['max_redirects'])->toBe(0)
        ->and($result['part'])->toBe([
            'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode("\x89PNG")],
        ]);
});

test('a relative redirect is resolved against the current URL', function () {
    $result = geminiImageDownload('http://93.184.216.34/dir/a.png', [
        'http://93.184.216.34/dir/a.png' => geminiImageRedirectTo('b.png'),
        'http://93.184.216.34/dir/b.png' => geminiImagePngResponse(),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/dir/a.png', 'http://93.184.216.34/dir/b.png'])
        ->and($result['part'])->toHaveKey('inlineData');
});

test('three redirects are followed', function () {
    $result = geminiImageDownload('http://93.184.216.34/0.png', [
        'http://93.184.216.34/0.png' => geminiImageRedirectTo('/1.png'),
        'http://93.184.216.34/1.png' => geminiImageRedirectTo('/2.png'),
        'http://93.184.216.34/2.png' => geminiImageRedirectTo('/3.png'),
        'http://93.184.216.34/3.png' => geminiImagePngResponse(),
    ]);

    expect($result['part'])->toHaveKey('inlineData');
});

test('a fourth redirect is refused', function () {
    $result = geminiImageDownload('http://93.184.216.34/0.png', [
        'http://93.184.216.34/0.png' => geminiImageRedirectTo('/1.png'),
        'http://93.184.216.34/1.png' => geminiImageRedirectTo('/2.png'),
        'http://93.184.216.34/2.png' => geminiImageRedirectTo('/3.png'),
        'http://93.184.216.34/3.png' => geminiImageRedirectTo('/4.png'),
        'http://93.184.216.34/4.png' => geminiImagePngResponse(),
    ]);

    expect($result['gets'])->toHaveCount(4)
        ->and($result['gets'])->not->toContain('http://93.184.216.34/4.png')
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('a redirect to a non-http scheme is refused', function () {
    $result = geminiImageDownload('http://93.184.216.34/a.png', [
        'http://93.184.216.34/a.png' => geminiImageRedirectTo('file:///etc/passwd'),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/a.png'])
        ->and($result['part']['text'])->toContain('[Image: could not download');
});

test('the provider API client is never used for an image fetch', function () {
    $imageClient = new MockHttpClient(fn() => geminiImagePngResponse());
    $sawImageUrl = false;
    // The image fetch catches every Throwable, so the flag below also catches a swallowed fail().
    $apiClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$sawImageUrl): MockResponse {
        if (str_contains($url, '93.184.216.34')) {
            $sawImageUrl = true;
            test()->fail("The API client was asked for the image URL {$url}");
        }

        return new MockResponse((string) json_encode([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
        ]), ['http_code' => 200]);
    });

    $provider = new GeminiProvider(apiKey: 'test-key', httpClient: $apiClient, imageHttpClient: $imageClient);
    $provider->chat([
        new UserMessage([['type' => 'image_url', 'image_url' => ['url' => 'http://93.184.216.34/a.png']]]),
    ]);

    expect($sawImageUrl)->toBeFalse()
        ->and($imageClient->getRequestsCount())->toBe(1)
        ->and($apiClient->getRequestsCount())->toBe(1);
});
