<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

/**
 * One tool as a server describes it in `tools/list`, with the values decoded by
 * json_decode(…, true).
 *
 * fingerprint() is what an approval is pinned to. A server can redefine a tool after a
 * person approved it, and a description is text the model reads, so a host allowlists
 * tools by this digest and withholds any tool whose digest moved (McpToolkit). It is
 * the sha256 of a canonical encoding of `name`, `description`, `inputSchema` and
 * `annotations`:
 * - every non-list array has its keys sorted (SORT_STRING), recursively;
 * - lists keep their order, since reordering an enum changes what a tool accepts;
 * - the encoding is json_encode() with the flags below, called with `serialize_precision`
 *   set to -1 and set back to its prior value afterwards, so a php.ini that sets another
 *   value does not move the digest of a float;
 * - when json_encode() returns false, the digest is of serialize() of the same canonical
 *   array instead.
 *
 * json_encode() returns false for INF and -INF, which json_decode() makes of `1e999` and
 * `-1e999`, and for arrays nested deeper than its default depth of 512. serialize() writes
 * INF as `d:INF;` and -INF as `d:-INF;`, and it carries the description, so a server that
 * rewrites the description of a tool whose schema holds `1e999` moves the digest. The JSON
 * encoding of the canonical array starts with `{` and its serialize() form with `a:`, so
 * the two paths never hash the same string.
 *
 * It runs the same canonical(), flags, precision hold and fallback as Alpaca Bot's
 * AlpacaBot\Mcp\ToolDefinition::fingerprint() at Alpaca Bot commit 3a2682e, whose stored
 * pins must keep matching; McpToolDefinitionTest pins digests that class gives for the
 * same definitions. The top-level `title` is outside the hash because it is a display
 * label. `annotations` is hashed as sent, `annotations.title` included. `outputSchema`,
 * `icons` and `_meta` are not kept.
 *
 * Some different inputs hash the same:
 * - JSON_INVALID_UTF8_SUBSTITUTE writes U+FFFD in place of invalid UTF-8, so `"bad\xC3"`,
 *   `"bad\xFF"` and `"bad\u{FFFD}"` give one digest;
 * - json_decode(…, true) makes one PHP value of `{}` and `[]`, so they hash the same, and
 *   so do an `annotations` of `{}` and none, since the constructor's default is `[]`;
 * - an object keyed by the numeric strings "0" to "n" decodes to a PHP array keyed 0 to
 *   n, which json_encode() writes as a list once canonical() has sorted its keys, so
 *   `{"1":"b","0":"a"}` and `["a","b"]` hash the same;
 * - two number literals that decode to one float hash the same: `9007199254740992.0` and
 *   `9007199254740993.0`, or the integers `99999999999999999999` and
 *   `100000000000000000000`, which are past PHP_INT_MAX and decode to floats. The integer
 *   literals `9007199254740992` and `9007199254740993` decode to two ints and hash apart.
 *
 * The hint methods read the MCP ToolAnnotations with the specification's defaults: a
 * missing hint means readOnly false, destructive true, idempotent false, openWorld true;
 * a value that isn't a boolean counts as missing. The specification says to treat these
 * hints as untrusted unless the server is trusted, so they are for deciding when to ask
 * a person, never for granting anything.
 */
final readonly class McpToolDefinition
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<array-key, mixed> $inputSchema
     * @param array<array-key, mixed> $annotations
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $annotations = [],
        public ?string $title = null,
    ) {}

    public function fingerprint(): string
    {
        $canonical = self::canonical([
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => $this->annotations,
        ]);

        $precision = (string) ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $json = json_encode($canonical, self::JSON_FLAGS);

            return hash('sha256', $json !== false ? $json : serialize($canonical));
        } finally {
            ini_set('serialize_precision', $precision);
        }
    }

    public function readOnly(): bool
    {
        return ($this->annotations['readOnlyHint'] ?? null) === true;
    }

    public function destructive(): bool
    {
        return !$this->readOnly() && ($this->annotations['destructiveHint'] ?? null) !== false;
    }

    public function idempotent(): bool
    {
        return !$this->readOnly() && ($this->annotations['idempotentHint'] ?? null) === true;
    }

    public function openWorld(): bool
    {
        return ($this->annotations['openWorldHint'] ?? null) !== false;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
