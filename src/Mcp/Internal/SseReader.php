<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Internal;

/**
 * Finds the JSON-RPC response to one request id in a buffered `text/event-stream` body.
 *
 * The events before it may be comments (`:`), notifications (a `method` and no id) or
 * server-to-client requests (a `method` and an id, possibly the same id as ours). Spec §2
 * skips anything carrying a `method`, and "carrying" is read as the key being present:
 * array_key_exists, not isset, so a `"method": null` frame is skipped too. Only an id match
 * on a frame with no `method` key at all is taken as the response. Multi-line `data:` fields
 * are joined with "\n" as the SSE format says.
 *
 * CRLF, LF and CR are all accepted as line ends, inside an event and between events. An
 * event ends at a blank line, which is two line ends in a row: the three pair into eight
 * byte sequences — `\r\n` is one line end, so a CR followed by an LF is not a pair — and
 * the split below matches all eight with `(?>\r\n|\r|\n){2}` rather than listing them.
 * Listing them is what went wrong twice: the first list here missed three of the eight,
 * and the five-alternative list proposed to repair it still missed two, each miss joining
 * two events into one payload that fails json_decode and loses the response. The group is
 * atomic because a plain `(?:\r\n|\r|\n){2}` backtracks — it reads a single CRLF as CR + LF
 * and ends the event in the middle of a `data:` payload.
 *
 * Provider\SseStreamParser is not reused. Checked against src/Provider/SseStreamParser.php
 * as it stands: events() is a generator driven by HttpClientInterface::stream(), so it
 * streams; a `data:` payload that fails json_decode() is `continue`d with no error; and
 * nothing in the file reads a JSON-RPC id. This client instead reads a body already capped
 * and buffered by HttpExchange, and has to match an id, so it is a pure function over that
 * string.
 *
 * @internal
 */
final class SseReader
{
    /** @return array<array-key, mixed>|null */
    public static function find(string $body, int $id): ?array
    {
        foreach (preg_split('/(?>\r\n|\r|\n){2}/', $body) ?: [] as $event) {
            $data = [];
            foreach (preg_split('/\r\n|\n|\r/', $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $value = substr($line, 5);
                    $data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
                }
            }
            if ($data === []) {
                continue;
            }
            $message = json_decode(implode("\n", $data), true);
            if (is_array($message) && !array_key_exists('method', $message) && ($message['id'] ?? null) === $id) {
                return $message;
            }
        }

        return null;
    }
}
