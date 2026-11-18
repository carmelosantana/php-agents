<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Embedding\OllamaEmbeddingProvider;
use CarmeloSantana\PHPAgents\Embedding\OpenAIEmbeddingProvider;
use CarmeloSantana\PHPAgents\Memory\Document;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @param list<array<string, mixed>> $captured
 */
function embeddingLimitsClient(array &$captured): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured[] = $options;

        return new MockResponse(json_encode([
            'embedding' => [0.1, 0.2],
            'data' => [['embedding' => [0.1, 0.2]]],
        ], JSON_THROW_ON_ERROR));
    });
}

function embeddingLimitsProvider(string $name, MockHttpClient $client): OpenAIEmbeddingProvider|OllamaEmbeddingProvider
{
    return match ($name) {
        'openai' => new OpenAIEmbeddingProvider(apiKey: 'test-key', httpClient: $client),
        'ollama' => new OllamaEmbeddingProvider(httpClient: $client),
    };
}

dataset('embedding providers', ['openai', 'ollama']);

test('embedText() sends max_redirects 0 and max_duration 600.0 with timeout 60.0', function (string $name) {
    $captured = [];
    $provider = embeddingLimitsProvider($name, embeddingLimitsClient($captured));

    $provider->embedText('hello');

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['max_redirects'])->toBe(0)
        ->and($captured[0]['max_duration'])->toBe(600.0)
        ->and($captured[0]['timeout'])->toBe(60.0)
        ->and(is_callable($captured[0]['on_progress']))->toBeTrue()
        ->and($provider->requestLimits()->idleTimeout)->toBe(60.0);
})->with('embedding providers');

test('embedDocuments() sends the same limits', function (string $name) {
    $captured = [];
    $provider = embeddingLimitsProvider($name, embeddingLimitsClient($captured));

    $provider->embedDocuments([new Document('a')]);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['max_redirects'])->toBe(0)
        ->and($captured[0]['max_duration'])->toBe(600.0)
        ->and($captured[0]['timeout'])->toBe(60.0);
})->with('embedding providers');

test('withRequestLimits() applies to later requests and leaves the original untouched', function (string $name) {
    $captured = [];
    $original = embeddingLimitsProvider($name, embeddingLimitsClient($captured));
    $limits = new RequestLimits(idleTimeout: 60.0, maxDuration: 45.0);

    $limited = $original->withRequestLimits($limits);
    $limited->embedText('a');
    $original->embedText('b');

    expect($limited)->not->toBe($original)
        ->and($limited->requestLimits())->toBe($limits)
        ->and($captured[0]['max_duration'])->toBe(45.0)
        ->and($captured[1]['max_duration'])->toBe(600.0);
})->with('embedding providers');
