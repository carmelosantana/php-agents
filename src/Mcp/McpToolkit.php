<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\SchemaTool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * One MCP server's tools as a php-agents toolkit, limited to the tools a person
 * approved and pinned to a fingerprint.
 *
 * $allow maps a tool's server name to the McpToolDefinition::fingerprint() that was
 * approved for it. A listed tool is exposed only when $allow holds a string under the
 * tool's name and that string equals the tool's live fingerprint, compared with
 * hash_equals(). A listed tool whose pin is a string that does not match is withheld and
 * collected instead, and the collected definitions go to $onDrift once per instance, only
 * when at least one tool drifted, so the host can ask a person to approve the new
 * definition. A listed tool with no pin at all, or with a pin that is not a string, is
 * skipped and is not reported as drift. A pinned tool the server does not list is simply
 * absent.
 *
 * Exposed names come from $namer when one is given, and otherwise from
 * McpToolName::fit("{$prefix}__{$name}"), or fit($name) when $prefix is ''. A namer that
 * answers with anything but a non-empty string throws \UnexpectedValueException, which
 * sits outside the McpException tree because it is the host's own closure that
 * misbehaved, not the server. When two tools reach the same exposed name, the one listed
 * later replaces the earlier.
 *
 * Each exposed tool is a SchemaTool carrying the server's description and its
 * `inputSchema`, whose closure calls $client->callTool() under the name the server listed
 * it by, not under the exposed name.
 *
 * listTools() runs at most once per instance, on whichever of tools() and definition() is
 * called first, and a listing that throws is not remembered: the next call lists again.
 * The one cache is the $exposed property, so a second instance over the same client lists
 * again and sees a definition that changed in between. An exception from listing leaves
 * tools() for the caller, because the library does not guess a host's failure policy. An
 * exception from a tool's call does not: SchemaTool turns it into its fixed error
 * ToolResult, putting the cause's class and message in that result's metadata under a
 * `schema_tool_error` errorCode instead of in the content the model reads.
 *
 * guidelines() returns ''. A server's `instructions` is text from outside, and handing it
 * to the model would put a second, unpinned description in front of it.
 */
final class McpToolkit implements ToolkitInterface
{
    /** @var array<string, array{definition: McpToolDefinition, tool: SchemaTool}>|null */
    private ?array $exposed = null;

    /**
     * @param array<array-key, mixed> $allow tool name => pinned fingerprint
     * @param (\Closure(list<McpToolDefinition>): void)|null $onDrift
     * @param (\Closure(string): string)|null $namer
     */
    public function __construct(
        private readonly McpClientInterface $client,
        private readonly array $allow,
        private readonly string $prefix = '',
        private readonly ?\Closure $onDrift = null,
        private readonly ?\Closure $namer = null,
    ) {}

    /** @return list<SchemaTool> */
    public function tools(): array
    {
        return array_values(array_map(static fn(array $entry): SchemaTool => $entry['tool'], $this->exposed()));
    }

    public function guidelines(): string
    {
        return '';
    }

    /** The live definition behind an exposed name, or null when that name is not exposed. */
    public function definition(string $exposedName): ?McpToolDefinition
    {
        return $this->exposed()[$exposedName]['definition'] ?? null;
    }

    /** @return array<string, array{definition: McpToolDefinition, tool: SchemaTool}> */
    private function exposed(): array
    {
        if ($this->exposed !== null) {
            return $this->exposed;
        }

        $exposed = [];
        $drifted = [];
        foreach ($this->client->listTools() as $definition) {
            $pin = $this->allow[$definition->name] ?? null;
            if (!is_string($pin)) {
                continue;
            }
            if (!hash_equals($pin, $definition->fingerprint())) {
                $drifted[] = $definition;

                continue;
            }
            $client = $this->client;
            $serverName = $definition->name;
            $name = $this->exposedName($serverName);
            $exposed[$name] = [
                'definition' => $definition,
                'tool' => new SchemaTool(
                    $name,
                    $definition->description,
                    $definition->inputSchema,
                    static fn(array $arguments): ToolResult => $client->callTool($serverName, $arguments),
                ),
            ];
        }

        $this->exposed = $exposed;
        if ($drifted !== [] && $this->onDrift !== null) {
            ($this->onDrift)($drifted);
        }

        return $exposed;
    }

    private function exposedName(string $tool): string
    {
        if ($this->namer !== null) {
            $name = ($this->namer)($tool);
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('The MCP toolkit namer must return a non-empty string.');
            }

            return $name;
        }

        return McpToolName::fit($this->prefix === '' ? $tool : "{$this->prefix}__{$tool}");
    }
}
