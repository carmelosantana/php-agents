<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Provider\SseStreamParser;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Tests\Support\Http\ScriptedStreamClient;

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

test('a newline-terminated line over the cap throws before it is decoded', function () {
    // 65 bytes, ending in a newline, in one chunk.
    $line = 'data: {"v":"' . str_repeat('x', 65 - 14) . '"}';
    expect(strlen($line))->toBe(65);
    $parser = makeParser([$line . "\n\n"], maxLineBytes: 64);

    $thrown = sseParserCatch($parser);

    expect($thrown)->toBeInstanceOf(ProviderException::class)
        ->and($thrown?->getMessage())->toContain('SSE line exceeded 64 bytes');
});

test('a newline-terminated line at exactly the cap is decoded', function () {
    $line = 'data: {"v":"' . str_repeat('x', 64 - 14) . '"}';
    $parser = makeParser([$line . "\n\n"], maxLineBytes: 64);

    expect(iterator_to_array($parser->events()))->toHaveCount(1);
});

test('a line over the cap that is completed by a later chunk throws', function () {
    $parser = makeParser([str_repeat('a', 40), str_repeat('b', 40) . "\n"], maxLineBytes: 64);

    expect(sseParserCatch($parser)?->getMessage())->toContain('SSE line exceeded 64 bytes');
});

test('events before an oversized line are still yielded first', function () {
    $parser = makeParser(["data: {\"n\":1}\n" . str_repeat('a', 65) . "\n"], maxLineBytes: 64);
    $seen = [];

    try {
        foreach ($parser->events() as $event) {
            $seen[] = $event;
        }
    } catch (ProviderException) {
    }

    expect($seen)->toBe([['n' => 1]]);
});

test('a chunk of many short lines is scanned without splitting it into an array', function () {
    // explode() on 2M newlines allocates a 2M-element array (tens of MiB); scanning does not.
    $parser = makeParser([str_repeat("\n", 2_000_000) . "data: {\"n\":1}\n"]);
    $before = memory_get_usage();
    memory_reset_peak_usage();

    $events = iterator_to_array($parser->events());

    expect($events)->toBe([['n' => 1]])
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
});

test('a CRLF line at exactly the cap is decoded: the CR belongs to the newline', function (array $chunks) {
    $parser = makeParser($chunks, maxLineBytes: 64);

    expect(iterator_to_array($parser->events()))->toHaveCount(1);
})->with(function () {
    $line = 'data: {"v":"' . str_repeat('x', 64 - 14) . '"}';

    return [
        'one chunk' => [[$line . "\r\n\r\n"]],
        'CR and LF split across chunks' => [[$line . "\r", "\n\r\n"]],
    ];
});

test('a CRLF line over the cap still throws', function () {
    $line = 'data: {"v":"' . str_repeat('x', 65 - 14) . '"}';

    expect(sseParserCatch(makeParser([$line . "\r\n"], maxLineBytes: 64))?->getMessage())->toContain('SSE line exceeded 64 bytes');
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

// Idle ticks. MockResponse turns a '' body chunk into a timeout chunk at once; ScriptedStreamClient
// plays chunks back in wall-clock time, one poll per stream() call.

/**
 * Build a ticking SseStreamParser over a MockResponse body.
 *
 * @param list<string> $chunks
 * @param array<string, mixed> $responseInfo
 */
function makeTickingParser(array $chunks, float $tickInterval = 0.25, float $idleTimeout = 0.0, array $responseInfo = []): SseStreamParser
{
    $client = new MockHttpClient([new MockResponse((static fn() => yield from $chunks)(), $responseInfo + ['http_code' => 200])]);
    $response = $client->request('GET', 'https://example.com/stream');

    return new SseStreamParser($client, $response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, $tickInterval, $idleTimeout);
}

/**
 * Drain events into a list, writing each Tick as the string 'tick'.
 *
 * @return list<mixed>
 */
function sseParserDrain(SseStreamParser $parser): array
{
    $seen = [];
    foreach ($parser->events() as $event) {
        $seen[] = $event instanceof Tick ? 'tick' : $event;
    }

    return $seen;
}

test('ticks while waiting for headers, then yields the data', function () {
    $client = new ScriptedStreamClient([
        [ScriptedStreamClient::TIMEOUT],
        [ScriptedStreamClient::TIMEOUT],
        [new FirstChunk(), new DataChunk(0, "data: {\"n\":1}\n\n"), new LastChunk()],
    ]);
    $parser = new SseStreamParser($client, $client->response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, 0.25, 300.0);

    expect(sseParserDrain($parser))->toBe(['tick', 'tick', ['n' => 1]])
        ->and($client->timeouts)->toBe([0.25, 0.25, 0.25]);
});

test('silence between data yields ticks and the stream still completes', function () {
    $parser = makeTickingParser(["data: {\"n\":1}\n\n", '', '', '', "data: {\"n\":2}\n\n"], idleTimeout: 300.0);

    expect(sseParserDrain($parser))->toBe([['n' => 1], 'tick', 'tick', 'tick', ['n' => 2]]);
});

test('a line split by a silence is still reassembled', function () {
    $parser = makeTickingParser(['data: {"msg":"a', '', "b\"}\n\n"]);

    expect(sseParserDrain($parser))->toBe(['tick', ['msg' => 'ab']]);
});

test('silence past the idle timeout throws symfony\'s idle timeout after ticking', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, "data: {\"n\":1}\n\n")],
    ], tailWait: 0.02);
    $parser = new SseStreamParser($client, $client->response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, 0.02, 0.1);

    $seen = [];
    $thrown = null;
    $start = hrtime(true) / 1e9;
    try {
        foreach ($parser->events() as $event) {
            $seen[] = $event instanceof Tick ? 'tick' : $event;
        }
    } catch (Throwable $e) {
        $thrown = $e;
    }
    $elapsed = hrtime(true) / 1e9 - $start;

    expect($thrown)->toBeInstanceOf(TimeoutException::class)
        ->and($thrown?->getMessage())->toBe(sprintf('Idle timeout reached for "%s".', ScriptedStreamClient::URL))
        ->and($seen[0])->toBe(['n' => 1])
        ->and(count($seen))->toBeGreaterThan(2)
        ->and(array_slice($seen, 1))->each->toBe('tick')
        ->and($elapsed)->toBeGreaterThanOrEqual(0.1)
        ->and($elapsed)->toBeLessThan(1.0);
});

test('comment lines reset the idle deadline without yielding an event', function () {
    // Four polls of 80 ms, each with a keep-alive comment halfway: 320 ms in all, never 100 ms
    // without a chunk. Counting only `data:` lines would time out on the second poll. Each
    // keep-alive arrives 40 ms after the last tick, past the 20 ms interval, so it brings a
    // data-flow tick of its own before the poll's idle tick.
    $polls = [];
    for ($i = 0; $i < 4; $i++) {
        $polls[] = [0.04, new DataChunk(0, ": keep-alive\n"), 0.04, ScriptedStreamClient::TIMEOUT];
    }
    array_unshift($polls[0], new FirstChunk());
    $polls[] = [new DataChunk(0, "data: {\"n\":1}\n\n"), new LastChunk()];
    $client = new ScriptedStreamClient($polls);
    $parser = new SseStreamParser($client, $client->response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, 0.02, 0.1);

    expect(sseParserDrain($parser))->toBe([...array_fill(0, 8, 'tick'), ['n' => 1]]);
});

test('ticks never extend the idle deadline when no idle timeout is set', function () {
    $parser = makeTickingParser(['', '', "data: {\"n\":1}\n\n"], idleTimeout: 0.0);

    expect(sseParserDrain($parser))->toBe(['tick', 'tick', ['n' => 1]]);
});

test('a zero tick interval polls without a timeout and yields no ticks', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, "data: {\"n\":1}\n\n"), new DataChunk(0, "data: {\"n\":2}\n\n"), new LastChunk()],
    ]);
    $parser = new SseStreamParser($client, $client->response);

    expect(sseParserDrain($parser))->toBe([['n' => 1], ['n' => 2]])
        ->and($client->timeouts)->toBe([null]);
});

test('a zero tick interval still throws on a timeout chunk, as before', function () {
    $parser = makeTickingParser(["data: {\"n\":1}\n\n", '', "data: {\"n\":2}\n\n"], tickInterval: 0.0);

    expect(fn() => sseParserDrain($parser))->toThrow(TimeoutException::class, 'Idle timeout reached');
});

test('a 4xx answer still throws the Symfony client exception when ticking', function () {
    $parser = makeTickingParser(['{"error":"bad key"}'], responseInfo: ['http_code' => 401]);

    $thrown = sseParserCatch($parser);

    expect($thrown)->toBeInstanceOf(ClientExceptionInterface::class)
        ->and($thrown?->getMessage())->toContain('401');
});

test('a 4xx answer that never sends a first chunk still throws on its first data chunk', function () {
    $client = new ScriptedStreamClient([
        [ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, "data: {\"error\":\"bad key\"}\n\n"), new LastChunk()],
    ], status: 401);
    $parser = new SseStreamParser($client, $client->response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, 0.25);

    $seen = [];
    $thrown = null;
    try {
        foreach ($parser->events() as $event) {
            $seen[] = $event instanceof Tick ? 'tick' : $event;
        }
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ClientExceptionInterface::class)
        ->and($thrown?->getMessage())->toContain('401')
        ->and($seen)->toBe(['tick']);
});

test('a non-timeout error chunk while ticking propagates', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, "data: {\"n\":1}\n\n")],
        [new ErrorChunk(0, new TransportException('Max duration was reached.'))],
    ]);
    $parser = new SseStreamParser($client, $client->response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, 0.25, 300.0);

    $seen = [];
    $thrown = null;
    try {
        foreach ($parser->events() as $event) {
            $seen[] = $event instanceof Tick ? 'tick' : $event;
        }
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(TransportException::class)
        ->and($thrown?->getMessage())->toBe('Max duration was reached.')
        ->and($seen)->toBe([['n' => 1], 'tick']);
});

test('a 3xx answer is still refused when ticking', function () {
    $parser = makeTickingParser(["data: {}\n\n"], responseInfo: [
        'http_code' => 302,
        'response_headers' => ['Location: https://evil.example/'],
    ]);

    expect(sseParserCatch($parser)?->getMessage())->toContain('redirect not followed');
});

test('a steady stream still yields a tick at least every tick interval', function () {
    // Ten payloads 30 ms apart with a 50 ms interval: never a silent poll, yet ticks interleave.
    $steps = [new FirstChunk()];
    for ($i = 0; $i < 10; $i++) {
        $steps[] = 0.03;
        $steps[] = new DataChunk(0, "data: {\"n\":{$i}}\n\n");
    }
    $steps[] = new LastChunk();
    $client = new ScriptedStreamClient([$steps]);
    $parser = new SseStreamParser($client, $client->response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, 0.05, 5.0);

    $items = sseParserDrain($parser);

    expect(count(array_filter($items, fn($i) => $i === 'tick')))->toBeGreaterThanOrEqual(4)
        ->and(array_values(array_filter($items, fn($i) => $i !== 'tick')))->toHaveCount(10);
});

test('one huge chunk of skipped lines still yields ticks while it is scanned', function () {
    // Comments, blank lines, [DONE] and malformed JSON yield nothing; scanning 2.4 million of
    // them takes far longer than the 5 ms interval, so ticks must come from inside the chunk.
    $skipped = str_repeat(": keep-alive\n\ndata: [DONE]\ndata: {oops\n", 600_000);
    $client = new ScriptedStreamClient([[new FirstChunk(), new DataChunk(0, $skipped . "data: {\"n\":1}\n\n"), new LastChunk()]]);
    $parser = new SseStreamParser($client, $client->response, SseStreamParser::DEFAULT_MAX_LINE_BYTES, 0.005, 5.0);

    $items = sseParserDrain($parser);
    $beforePayload = array_slice($items, 0, (int) array_search(['n' => 1], $items, true));

    expect(array_values(array_filter($items, fn($i) => $i !== 'tick')))->toBe([['n' => 1]])
        ->and(count($beforePayload))->toBeGreaterThanOrEqual(3);
});
