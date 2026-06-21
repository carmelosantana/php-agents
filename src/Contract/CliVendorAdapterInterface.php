<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessChunk;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessRequest;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessResult;
use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * Vendor-specific knowledge for a CLI-backed provider.
 *
 * This is the expandable seam: one adapter per CLI binary (claude, codex, grok,
 * deepseek, ...). The generic CliProvider composes an adapter with a
 * CliRuntimeInterface, so adding a new CLI vendor means writing an adapter — not
 * a new provider class.
 */
interface CliVendorAdapterInterface
{
    /**
     * The executable name (looked up on PATH unless an absolute path).
     */
    public function binaryName(): string;

    /**
     * Build the process invocation for a chat/stream turn.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     */
    public function buildRequest(
        array $messages,
        array $tools,
        array $options,
        string $model,
        bool $stream,
    ): CliProcessRequest;

    /**
     * Parse a completed non-streaming execution into a Response.
     */
    public function parseResult(CliProcessResult $result, string $model): Response;

    /**
     * Translate a single streaming stdout fragment into a Response, if it
     * carries any user-visible content/usage. Implementations may buffer across
     * calls; the carry value is passed back on the next invocation.
     *
     * @param array<string, mixed> $carry Mutable parser state between chunks
     */
    public function parseChunk(CliProcessChunk $chunk, string $model, array &$carry): ?Response;

    /**
     * Whether this vendor supports incremental streaming output.
     */
    public function isStreamingSupported(): bool;

    /**
     * Curated/discovered models offered by this vendor.
     *
     * @return ModelDefinition[]
     */
    public function discoverModels(CliRuntimeInterface $runtime): array;
}
