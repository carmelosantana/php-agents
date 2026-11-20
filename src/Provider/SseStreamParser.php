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
 * yields a Tick for each poll that brings no bytes. While data flows it also
 * yields a Tick whenever $tickInterval has passed since the last one, before
 * the next payload, after each chunk and every few hundred lines it scans inside a chunk, so a consumer whose provider turns
 * many payloads into no event (fragments of a call over the argument cap,
 * pings, signature deltas) still gets a checkpoint that often. The idle deadline is
 * then the parser's: once $idleTimeout seconds (0.0 = none) pass without
 * a chunk it throws symfony's idle-timeout TimeoutException. The
 * response's `max_duration` stays with symfony.
 */
final class SseStreamParser
{
    /**
     * Largest SSE line, in bytes and without its newline (LF or CRLF), complete or still waiting
     * for its newline. A bare CR does not end a line.
     */
    public const int DEFAULT_MAX_LINE_BYTES = 1_048_576;

    private string $lineBuffer = '';

    /** Lines parseLines() scans between two looks at the clock. */
    private const int LINES_PER_TICK_CHECK = 256;

    /** When the last Tick went out (hrtime seconds); data-flow ticks are due $tickInterval after it. */
    private float $lastTick = 0.0;

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
     * body from it. Also throws a ProviderException when any line, complete
     * or still incomplete, grows past the line cap (before it is decoded), and
     * symfony's TimeoutException when the idle deadline passes.
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
        $this->lastTick = $lastData;

        while (true) {
            // A timeout chunk ends symfony's iterator for that response, so each
            // idle poll starts a new stream() call.
            foreach ($this->httpClient->stream($this->response, $pollTimeout) as $chunk) {
                if ($chunk->isTimeout()) {
                    if ($pollTimeout === null || ($this->idleTimeout > 0.0 && hrtime(true) / 1e9 - $lastData >= $this->idleTimeout)) {
                        throw new TimeoutException(sprintf('Idle timeout reached for "%s".', $this->response->getInfo('url')));
                    }

                    yield new Tick();
                    $this->lastTick = hrtime(true) / 1e9;

                    continue 2;
                }

                $lastData = hrtime(true) / 1e9;

                if ($chunk->isFirst()) {
                    $this->assertSuccessfulStatus();
                }

                // parseLines() yields its own Ticks while it scans lines that yield nothing.
                foreach ($this->parseLines($chunk->getContent()) as $json) {
                    if (!$json instanceof Tick && $this->tickDue()) {
                        yield new Tick();
                    }
                    yield $json;
                }
                if ($this->tickDue()) {
                    yield new Tick();
                }

                if ($chunk->isLast()) {
                    return;
                }
            }

            return;
        }
    }

    /**
     * Scan $content for newlines, joining its first line to any held partial line,
     * and yield the payload of each complete `data: ` line. A trailing partial line is
     * held for the next chunk. Every line, complete or partial, is measured against the
     * line cap before it is built or decoded, and the chunk is never split into an array.
     *
     * @return \Generator<int, array<string, mixed>|Tick>
     */
    private function parseLines(string $content): \Generator
    {
        $offset = 0;
        $length = strlen($content);
        $scanned = 0;

        while ($offset < $length) {
            // Skipped lines (comments, blanks, [DONE], malformed JSON) yield nothing, so a large
            // chunk of them gets a Tick every so often too.
            if (++$scanned % self::LINES_PER_TICK_CHECK === 0 && $this->tickDue()) {
                yield new Tick();
            }

            $newline = strpos($content, "\n", $offset);

            if ($newline === false) {
                // A trailing CR may be the first half of a CRLF split across chunks.
                $this->assertLineFits(strlen($this->lineBuffer) + $length - $offset - ($content[$length - 1] === "\r" ? 1 : 0));
                $this->lineBuffer .= substr($content, $offset);

                return;
            }

            // CRLF: the CR is part of the newline, so it is neither measured nor kept.
            $crlf = ($newline > $offset ? $content[$newline - 1] : substr($this->lineBuffer, -1)) === "\r";
            $this->assertLineFits(strlen($this->lineBuffer) + $newline - $offset - ($crlf ? 1 : 0));
            $line = $this->lineBuffer . substr($content, $offset, $newline - $offset - ($newline > $offset && $crlf ? 1 : 0));
            if ($crlf && $newline === $offset) {
                $line = substr($line, 0, -1);
            }
            $this->lineBuffer = '';
            $offset = $newline + 1;

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
     * Whether a data-flow Tick is due ($tickInterval since the last one); when it is, the tick
     * is counted as sent now.
     */
    private function tickDue(): bool
    {
        if ($this->tickInterval <= 0.0) {
            return false;
        }

        $now = hrtime(true) / 1e9;
        if ($now - $this->lastTick < $this->tickInterval) {
            return false;
        }
        $this->lastTick = $now;

        return true;
    }

    /**
     * Throw when a line of $bytes (its newline excluded) is over the line cap.
     */
    private function assertLineFits(int $bytes): void
    {
        if ($bytes > $this->maxLineBytes) {
            throw ProviderException::requestRefused(sprintf('SSE line exceeded %d bytes', $this->maxLineBytes));
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
