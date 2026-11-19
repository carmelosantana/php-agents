<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Parses Server-Sent Events from an HTTP streaming response.
 *
 * All providers use the same line-buffering logic to reassemble
 * incomplete SSE lines that span HTTP chunk boundaries. This class
 * extracts that shared pattern into a single, testable component.
 *
 * Usage:
 *     $parser = new SseStreamParser($httpClient, $response);
 *     foreach ($parser->events() as $data) {
 *         // $data is the decoded JSON payload of each `data: ` line
 *     }
 *
 * With a $tickInterval above 0.0 the parser polls the response every
 * $tickInterval seconds, from the request onward (headers included), and
 * yields a Tick for each poll that brings no bytes. The idle deadline is
 * then the parser's: once $idleTimeout seconds (0.0 = none) pass without
 * a chunk it throws symfony's idle-timeout TimeoutException. The
 * response's `max_duration` stays with symfony.
 */
final class SseStreamParser
{
    /** Largest incomplete SSE line, in bytes, held while waiting for its newline. */
    public const int DEFAULT_MAX_LINE_BYTES = 1_048_576;

    private string $lineBuffer = '';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ResponseInterface $response,
        private readonly int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
        private readonly float $tickInterval = 0.0,
        private readonly float $idleTimeout = 0.0,
    ) {}

    /**
     * Yield decoded JSON payloads from SSE `data: ` lines, and a Tick for
     * each $tickInterval that passes with no data.
     *
     * Handles:
     * - Line buffering across HTTP chunk boundaries
     * - Skipping non-data lines (event:, id:, retry:, comments)
     * - Skipping the `[DONE]` sentinel
     * - JSON decode errors (silently skipped)
     *
     * Checks the status on the first chunk: a 3xx throws a ProviderException
     * refusing the redirect, and a 4xx or 5xx throws Symfony's ClientException
     * or ServerException unchanged, so callers can still read the API error
     * body from it. Also throws a ProviderException when an incomplete line
     * grows past the line cap, and symfony's TimeoutException when the idle
     * deadline passes.
     *
     * With a $tickInterval of 0.0 it waits on symfony's own `timeout` and
     * yields no Tick.
     *
     * @return \Generator<int, array<string, mixed>|Tick>
     */
    public function events(): \Generator
    {
        $pollTimeout = $this->tickInterval > 0.0 ? $this->tickInterval : null;
        $lastData = hrtime(true) / 1e9;

        while (true) {
            // A timeout chunk ends symfony's iterator for that response, so each
            // idle poll starts a new stream() call.
            foreach ($this->httpClient->stream($this->response, $pollTimeout) as $chunk) {
                if ($chunk->isTimeout()) {
                    if ($pollTimeout === null || ($this->idleTimeout > 0.0 && hrtime(true) / 1e9 - $lastData >= $this->idleTimeout)) {
                        throw new TimeoutException(sprintf('Idle timeout reached for "%s".', $this->response->getInfo('url')));
                    }

                    yield new Tick();

                    continue 2;
                }

                $lastData = hrtime(true) / 1e9;

                if ($chunk->isFirst()) {
                    $this->assertSuccessfulStatus();
                }

                yield from $this->parseLines($chunk->getContent());

                if ($chunk->isLast()) {
                    return;
                }
            }

            return;
        }
    }

    /**
     * Buffer $content onto any held partial line and yield the payload of
     * each complete `data: ` line.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function parseLines(string $content): \Generator
    {
        $data = $this->lineBuffer . $content;
        $this->lineBuffer = '';

        $lines = explode("\n", $data);

        // If the data doesn't end with a newline, the last element
        // is an incomplete line — buffer it for the next chunk.
        if (!str_ends_with($data, "\n")) {
            $this->lineBuffer = array_pop($lines);

            if (strlen($this->lineBuffer) > $this->maxLineBytes) {
                throw ProviderException::requestRefused(sprintf('SSE line exceeded %d bytes', $this->maxLineBytes));
            }
        }

        foreach ($lines as $line) {
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }

            $payload = substr($line, 6);

            if ($payload === '[DONE]') {
                continue;
            }

            $json = json_decode($payload, true);
            if ($json === null) {
                continue;
            }

            yield $json;
        }
    }

    /**
     * Throw unless the status the first chunk carried is 2xx.
     *
     * A 3xx becomes ProviderException::redirectRefused(); a 4xx or 5xx
     * propagates as the Symfony exception getHeaders() raised.
     */
    private function assertSuccessfulStatus(): void
    {
        try {
            $this->response->getHeaders(true);
        } catch (RedirectionExceptionInterface $e) {
            throw ProviderException::redirectRefused($e);
        }
    }
}
