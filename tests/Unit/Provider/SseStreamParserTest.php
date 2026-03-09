<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\SseStreamParser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Build an SseStreamParser from a list of raw HTTP chunk strings.
 *
 * @param list<string> $chunks
 */
function makeParser(array $chunks): SseStreamParser
{
    $response = new MockResponse($chunks, ['http_code' => 200]);
    $client = new MockHttpClient([$response]);

    // The response must be issued by MockHttpClient before stream() works.
    // We call request() to bind the response to the client.
    $issuedResponse = $client->request('GET', 'https://example.com/stream');

    return new SseStreamParser($client, $issuedResponse);
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
