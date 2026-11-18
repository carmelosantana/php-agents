<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Provider\SseStreamParser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

/**
 * Build an SseStreamParser from a list of raw HTTP chunk strings.
 *
 * @param list<string> $chunks
 * @param array<string, mixed> $responseInfo
 */
function makeParser(array $chunks, ?int $maxLineBytes = null, array $responseInfo = []): SseStreamParser
{
    $response = new MockResponse($chunks, $responseInfo + ['http_code' => 200]);
    $client = new MockHttpClient([$response]);

    // The response must be issued by MockHttpClient before stream() works.
    // We call request() to bind the response to the client.
    $issuedResponse = $client->request('GET', 'https://example.com/stream');

    return $maxLineBytes === null
        ? new SseStreamParser($client, $issuedResponse)
        : new SseStreamParser($client, $issuedResponse, $maxLineBytes);
}

test('parses complete SSE events from a single chunk', function () {
    $parser = makeParser([
        "data: {\"id\":\"1\",\"content\":\"hello\"}\n\ndata: {\"id\":\"2\",\"content\":\"world\"}\n\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(2)
        ->and($events[0]['content'])->toBe('hello')
        ->and($events[1]['content'])->toBe('world');
});

test('handles line split across chunk boundaries', function () {
    // The JSON payload is split between two chunks
    $parser = makeParser([
        "data: {\"id\":\"1\",\"con",
        "tent\":\"split\"}\n\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(1)
        ->and($events[0]['content'])->toBe('split');
});

test('skips [DONE] sentinel', function () {
    $parser = makeParser([
        "data: {\"id\":\"1\"}\n\ndata: [DONE]\n\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe('1');
});

test('skips non-data lines (event, id, comments)', function () {
    $parser = makeParser([
        "event: message\nid: 42\nretry: 1000\n: this is a comment\ndata: {\"ok\":true}\n\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(1)
        ->and($events[0]['ok'])->toBeTrue();
});

test('skips malformed JSON gracefully', function () {
    $parser = makeParser([
        "data: not-json\ndata: {\"valid\":true}\n\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(1)
        ->and($events[0]['valid'])->toBeTrue();
});

test('handles chunk with only whitespace before real data', function () {
    $parser = makeParser([
        "\n\n",
        "data: {\"id\":\"1\"}\n\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(1);
});

test('handles multiple data lines in rapid succession', function () {
    $parser = makeParser([
        "data: {\"n\":1}\ndata: {\"n\":2}\ndata: {\"n\":3}\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(3)
        ->and($events[0]['n'])->toBe(1)
        ->and($events[1]['n'])->toBe(2)
        ->and($events[2]['n'])->toBe(3);
});

test('buffers incomplete final line across multiple chunks', function () {
    $parser = makeParser([
        "data: {\"part",
        "\":\"one",
        "\"}\n\n",
    ]);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(1)
        ->and($events[0]['part'])->toBe('one');
});

/** Drains the parser and returns what it threw, or null. */
function sseParserCatch(SseStreamParser $parser): ?Throwable
{
    try {
        iterator_to_array($parser->events());
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

test('a data line split across chunks still yields one event', function () {
    $parser = makeParser([
        'data: {"msg":"a',
        'b"}',
        "\n\n",
    ], maxLineBytes: 64);

    $events = iterator_to_array($parser->events());

    expect($events)->toHaveCount(1)
        ->and($events[0]['msg'])->toBe('ab');
});

test('an unterminated line over the cap throws', function () {
    // 65 bytes with no newline, split across two chunks: one byte over a 64-byte cap.
    $parser = makeParser([str_repeat('a', 32), str_repeat('b', 33)], maxLineBytes: 64);

    $thrown = sseParserCatch($parser);

    expect($thrown)->toBeInstanceOf(ProviderException::class)
        ->and($thrown?->getMessage())->toContain('SSE line exceeded 64 bytes');
});

test('an unterminated line at exactly the cap is still buffered', function () {
    $parser = makeParser([str_repeat('a', 32), str_repeat('b', 32)], maxLineBytes: 64);

    expect(sseParserCatch($parser))->toBeNull();
});

test('the default line cap is 1048576 bytes', function () {
    $parser = makeParser([str_repeat('a', 1_048_576), 'b']);

    expect(sseParserCatch($parser)?->getMessage())->toContain('SSE line exceeded 1048576 bytes');
});

test('a 307 stream answer is refused as a redirect not followed', function () {
    $parser = makeParser(["data: {\"ok\":true}\n\n"], responseInfo: [
        'http_code' => 307,
        'response_headers' => ['Location: https://evil.example/'],
    ]);

    $thrown = sseParserCatch($parser);

    expect($thrown)->toBeInstanceOf(ProviderException::class)
        ->and($thrown?->getMessage())->toContain('Provider request refused')
        ->and($thrown?->getMessage())->toContain('redirect not followed');
});

test('a 4xx stream answer propagates the Symfony client exception naming the status', function () {
    $parser = makeParser(['{"error":"bad key"}'], responseInfo: ['http_code' => 401]);

    $thrown = sseParserCatch($parser);

    expect($thrown)->toBeInstanceOf(ClientExceptionInterface::class)
        ->and($thrown?->getMessage())->toContain('401');
});

test('a 5xx stream answer propagates the Symfony server exception', function () {
    $parser = makeParser(['oops'], responseInfo: ['http_code' => 503]);

    expect(sseParserCatch($parser))->toBeInstanceOf(ServerExceptionInterface::class);
});
