<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Internal;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Turns a `tools/call` result into the ToolResult a model reads.
 *
 * What reaches the model is ToolResult::$content, a string: ToolResultMessage::toArray()
 * copies the content and the call id into the message it sends, and not the mimeType or
 * the metadata. So the string carries whatever the model is to learn about a block:
 * - `text` blocks are joined with a blank line. A block that is not an array, and a
 *   `text` block whose `text` is not a string, are dropped.
 * - An embedded `resource` with text contributes that text after a `[resource <uri>]`
 *   line.
 * - With no `text` block, a non-null `structuredContent` is pretty-printed JSON, placed
 *   first, and typed application/json when it ends up being the only part.
 * - The placeholder shapes, for the block types this mapper knows, are
 *   `[image <mime>, <n> bytes]` and the same for `audio`, where n is the decoded size
 *   of the base64; `[resource <uri> (<mime>), <n> bytes]` for an embedded resource
 *   carrying a blob, and a bare `[resource <uri>]` for one carrying neither `text` nor
 *   `blob`; and `[resource_link <name> <uri>]`. A type this mapper has no shape for is
 *   `[<type> block]`, or `[unknown block]` when `type` is absent or is not a string.
 *   Every field a shape interpolates falls back rather than dropping the block, and the
 *   shape keeps its own spaces either way. There are five, which is all of them: `<mime>`
 *   is the literal `unknown` when the block carries no string `mimeType`; `<type>` is
 *   `unknown` as above; `<n>` is 0 when the base64 is absent or fails a strict decode;
 *   and `<uri>` and `<name>` are the empty string, so a `resource_link` carrying neither
 *   reads `[resource_link  ]`, both spaces intact, a blob resource with no `uri` reads
 *   `[resource  (<mime>), <n> bytes]`, and an embedded resource with no `uri` — or with
 *   no `resource` object at all — reads `[resource ]`. A placeholder tells the model the
 *   block arrived, without handing it any base64.
 * - The joined string is cut to $maxBytes with mb_strcut, which does not split a UTF-8
 *   character — though it cannot repair a block the server sent as invalid UTF-8. The
 *   truncation note is appended after the cut, so a truncated content runs past
 *   $maxBytes by the length of that note. Truncated JSON loses its JSON type.
 *
 * The blocks stay as sent in `metadata['mcp']['content']` for the host; a `content` that
 * is not a list is recorded there as an empty list. Beside them sit `structuredContent`
 * when the result carried that key, `isError`, `bytes` (the joined size before the cap)
 * and `truncated`. An `isError` of exactly true gives an error result with the error code
 * `mcp_tool_error`, and the message `The tool reported an error.` when the content is
 * empty.
 *
 * The cap is the caller's: this class does no I/O and reads no configuration — it applies
 * the $maxBytes it is handed.
 *
 * @internal
 */
final class ResultMapper
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @param array<array-key, mixed> $result */
    public static function toToolResult(array $result, int $maxBytes): ToolResult
    {
        $blocks = is_array($result['content'] ?? null) && array_is_list($result['content']) ? $result['content'] : [];
        $isError = ($result['isError'] ?? false) === true;
        $parts = [];
        $hasText = false;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $part = self::part($block);
            if ($part === null) {
                continue;
            }
            $hasText = $hasText || ($block['type'] ?? null) === 'text';
            $parts[] = $part;
        }

        $mimeType = null;
        $structured = array_key_exists('structuredContent', $result) ? $result['structuredContent'] : null;
        if (!$hasText && $structured !== null) {
            array_unshift($parts, (string) json_encode($structured, self::JSON_FLAGS));
            $mimeType = count($parts) === 1 ? 'application/json' : null;
        }

        $content = implode("\n\n", $parts);
        $bytes = strlen($content);
        $truncated = $bytes > $maxBytes;
        if ($truncated) {
            $shown = mb_strcut($content, 0, $maxBytes, 'UTF-8');
            $content = $shown . sprintf("\n[truncated: %d of %d bytes]", strlen($shown), $bytes);
            $mimeType = null;
        }

        $meta = ['content' => $blocks, 'isError' => $isError, 'bytes' => $bytes, 'truncated' => $truncated];
        if (array_key_exists('structuredContent', $result)) {
            $meta['structuredContent'] = $result['structuredContent'];
        }

        if ($isError) {
            return ToolResult::error($content !== '' ? $content : 'The tool reported an error.')
                ->withErrorCode('mcp_tool_error')
                ->withMetadata(['mcp' => $meta]);
        }

        return ToolResult::success($content)->withMimeType($mimeType)->withMetadata(['mcp' => $meta]);
    }

    /** @param array<array-key, mixed> $block */
    private static function part(array $block): ?string
    {
        $type = $block['type'] ?? null;

        return match ($type) {
            'text' => is_string($block['text'] ?? null) ? $block['text'] : null,
            'image', 'audio' => sprintf('[%s %s, %d bytes]', $type, self::str($block['mimeType'] ?? null, 'unknown'), self::decodedSize($block['data'] ?? null)),
            'resource_link' => sprintf('[resource_link %s %s]', self::str($block['name'] ?? null, ''), self::str($block['uri'] ?? null, '')),
            'resource' => self::resource(is_array($block['resource'] ?? null) ? $block['resource'] : []),
            default => sprintf('[%s block]', is_string($type) ? $type : 'unknown'),
        };
    }

    /** @param array<array-key, mixed> $resource */
    private static function resource(array $resource): string
    {
        $uri = self::str($resource['uri'] ?? null, '');
        if (is_string($resource['text'] ?? null)) {
            return "[resource {$uri}]\n" . $resource['text'];
        }
        if (is_string($resource['blob'] ?? null)) {
            return sprintf('[resource %s (%s), %d bytes]', $uri, self::str($resource['mimeType'] ?? null, 'unknown'), self::decodedSize($resource['blob']));
        }

        return "[resource {$uri}]";
    }

    private static function decodedSize(mixed $base64): int
    {
        if (!is_string($base64)) {
            return 0;
        }
        $decoded = base64_decode($base64, true);

        return $decoded === false ? 0 : strlen($decoded);
    }

    private static function str(mixed $value, string $fallback): string
    {
        return is_string($value) ? $value : $fallback;
    }
}
