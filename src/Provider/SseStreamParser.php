<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Exception\ProviderException;
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
    ) {}

    /**
     * Yield decoded JSON payloads from SSE `data: ` lines.
     *
     * Handles:
     * - Line buffering across HTTP chunk boundaries
     * - Skipping non-data lines (event:, id:, retry:, comments)
     * - Skipping the `[DONE]` sentinel
     * - JSON decode errors (silently skipped)
     *
     * Checks the status before reading any chunk: a 3xx throws a
     * ProviderException refusing the redirect, and a 4xx or 5xx throws
     * Symfony's ClientException or ServerException unchanged, so callers
     * can still read the API error body from it. Also throws a
     * ProviderException when an incomplete line grows past the line cap.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function events(): \Generator
    {
        $this->assertSuccessfulStatus();

        foreach ($this->httpClient->stream($this->response) as $chunk) {
            $data = $this->lineBuffer . $chunk->getContent();
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
    }

    /**
     * Wait for the status line and throw unless it is 2xx.
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
