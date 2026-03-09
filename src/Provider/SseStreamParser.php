<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

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
    private string $lineBuffer = '';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ResponseInterface $response,
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
     * @return \Generator<int, array<string, mixed>>
     */
    public function events(): \Generator
    {
        foreach ($this->httpClient->stream($this->response) as $chunk) {
            $data = $this->lineBuffer . $chunk->getContent();
            $this->lineBuffer = '';

            $lines = explode("\n", $data);

            // If the data doesn't end with a newline, the last element
            // is an incomplete line — buffer it for the next chunk.
            if (!str_ends_with($data, "\n")) {
                $this->lineBuffer = array_pop($lines);
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
}
