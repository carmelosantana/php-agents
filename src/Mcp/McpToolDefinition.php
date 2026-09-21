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
 * the sha256 of a canonical JSON encoding of `name`, `description`, `inputSchema` and
 * `annotations`:
 * - every non-list array has its keys sorted (SORT_STRING), recursively;
 * - lists keep their order, since reordering an enum changes what a tool accepts;
 * - the encoding flags are the ones below.
 *
 * It is byte-identical to Alpaca Bot's AlpacaBot\Mcp\ToolDefinition::fingerprint(),
 * whose stored pins must keep matching; the test pins a shared digest. The top-level
 * `title` is outside the hash because it is a display label. `annotations` is hashed as
 * sent, `annotations.title` included. `outputSchema`, `icons` and `_meta` are not kept.
 * Invalid UTF-8 is substituted rather than thrown on, so a bad byte withholds a tool
 * instead of failing a turn.
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

        return hash('sha256', (string) json_encode($canonical, self::JSON_FLAGS));
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
