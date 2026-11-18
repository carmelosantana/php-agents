<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Image\ImagePolicy;
use CarmeloSantana\PHPAgents\Image\RemoteImageResolver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\Image\ImageFixtures;

/**
 * Resolves $url through a RemoteImageResolver backed by a MockHttpClient. $responses maps a
 * GET URL to its MockResponse; any other URL answers 404. $hostResolver, when given,
 * replaces the DNS lookup (private property, set by reflection). Returns the URLs asked
 * for, their options, and the resolved image or the exception thrown.
 *
 * @param array<string, MockResponse> $responses
 * @param (\Closure(string): list<string>)|null $hostResolver
 * @return array{gets: list<string>, options: list<array<string, mixed>>, image: ?\CarmeloSantana\PHPAgents\Image\ResolvedImage, error: ?\Throwable}
 */
function remoteImageResolve(string $url, array $responses = [], ?ImagePolicy $policy = null, ?\Closure $hostResolver = null): array
{
    $gets = [];
    $options = [];
    $client = new MockHttpClient(function (string $method, string $requested, array $requestOptions) use (&$gets, &$options, $responses): MockResponse {
        $gets[] = $requested;
        $options[] = $requestOptions;

        return $responses[$requested] ?? new MockResponse('', ['http_code' => 404]);
    });

    $resolver = new RemoteImageResolver(client: $client, policy: $policy ?? new ImagePolicy());
    if ($hostResolver !== null) {
        (new ReflectionProperty(RemoteImageResolver::class, 'hostResolver'))->setValue($resolver, $hostResolver);
    }

    $image = null;
    $error = null;
    try {
        $image = $resolver->resolve($url);
    } catch (\Throwable $e) {
        $error = $e;
    }

    return ['gets' => $gets, 'options' => $options, 'image' => $image, 'error' => $error];
}

function remoteImagePng(string $contentType = 'image/png'): MockResponse
{
    return new MockResponse(ImageFixtures::png(), ['http_code' => 200, 'response_headers' => ['content-type' => $contentType]]);
}

test('http://169.254.169.254/ is never requested', function () {
    $url = 'http://169.254.169.254/';
    $result = remoteImageResolve($url, [$url => remoteImagePng()]);

    expect($result['gets'])->toBe([])
        ->and($result['error'])->toBeInstanceOf(ImageInputException::class);
});

test('a redirect to 127.0.0.1 is refused', function () {
    $result = remoteImageResolve('http://93.184.216.34/a.png', [
        'http://93.184.216.34/a.png' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://127.0.0.1/a.png']]),
        'http://127.0.0.1/a.png' => remoteImagePng(),
    ]);

    expect($result['gets'])->toBe(['http://93.184.216.34/a.png'])
        ->and($result['error'])->toBeInstanceOf(ImageInputException::class);
});

test('a public png is returned with its sniffed mime', function () {
    // The server calls it a JPEG; the bytes say PNG, and the bytes win.
    $result = remoteImageResolve(
        'https://img.test/a.png',
        ['https://img.test/a.png' => remoteImagePng('image/jpeg')],
        hostResolver: fn(string $host): array => $host === 'img.test' ? ['93.184.216.34'] : [],
    );

    expect($result['error'])->toBeNull()
        ->and($result['image']?->mimeType)->toBe('image/png')
        ->and($result['image']?->bytes)->toBe(ImageFixtures::png())
        ->and($result['options'][0]['resolve'])->toBe(['img.test' => '93.184.216.34']);
});

test('every request bypasses any proxy', function () {
    $result = remoteImageResolve('http://93.184.216.34/a.png', ['http://93.184.216.34/a.png' => remoteImagePng()]);

    expect($result['options'][0]['no_proxy'])->toBe('*')
        ->and($result['options'][0]['proxy'])->toBeNull();
});

test('a body over maxBytes is refused', function () {
    $result = remoteImageResolve(
        'http://93.184.216.34/a.png',
        ['http://93.184.216.34/a.png' => remoteImagePng()],
        new ImagePolicy(maxBytes: 10),
    );

    expect($result['image'])->toBeNull()
        ->and($result['error'])->toBeInstanceOf(ImageInputException::class);
});

test('file:// and gopher:// are refused', function (string $url) {
    $result = remoteImageResolve($url);

    expect($result['gets'])->toBe([])
        ->and($result['error'])->toBeInstanceOf(ImageInputException::class);
})->with([
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://93.184.216.34/_a',
]);

test('a non-image body with image/png content type is refused', function () {
    $result = remoteImageResolve('http://93.184.216.34/a.png', [
        'http://93.184.216.34/a.png' => new MockResponse('<html></html>', ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]),
    ]);

    expect($result['image'])->toBeNull()
        ->and($result['error'])->toBeInstanceOf(ImageInputException::class);
});
