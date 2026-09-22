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
 * are joined with "\n" as the SSE format says, and CRLF, LF and CR line ends are all accepted.
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
        foreach (preg_split('/\r\n\r\n|\n\n|\r\r/', $body) ?: [] as $event) {
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
