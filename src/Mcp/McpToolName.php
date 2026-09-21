<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * Fits a tool name to what the providers php-agents reaches accept: at most 64
 * characters of `[A-Za-z0-9_-]`, first character a letter or `_` (OpenAI's limit
 * and the strictest first-character rule). MCP allows names of up to 128 characters
 * with dots.
 *
 * A name that already fits comes back unchanged. Any other name is changed and
 * marked: disallowed characters become `_`, a leading `_` is added when needed, and
 * the result is cut to 55 characters plus `_` and the first 8 hex characters of the
 * sha256 of the raw name. The mark keeps apart names the replacement would merge
 * (`repo.search` and `repo_search`). Because it is derived from the input, the same
 * tool gets the same name on every turn.
 *
 * This is the same rule as Alpaca Bot's Toolkit\ToolName::fit().
 */
final class McpToolName
{
    public const MAX = 64;

    public static function fit(string $raw): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $raw);
        if (preg_match('/^[A-Za-z_]/', $name) !== 1) {
            $name = '_' . $name;
        }
        if ($name === $raw && strlen($name) <= self::MAX) {
            return $name;
        }

        return substr($name, 0, self::MAX - 9) . '_' . substr(hash('sha256', $raw), 0, 8);
    }
}
