<?php

declare(strict_types=1);

namespace Tests\Support\Runtime;

use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessChunk;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessRequest;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessResult;

/**
 * In-memory CliRuntime test double.
 *
 * Captures the last request so tests can assert argv/stdin construction, and
 * returns canned results/chunks instead of spawning a real process.
 */
final class FakeCliRuntime implements CliRuntimeInterface
{
    public ?CliProcessRequest $lastRequest = null;

    private bool $available = true;

    private ?CliProcessResult $result = null;

    /** @var CliProcessChunk[] */
    private array $chunks = [];

    public function withAvailability(bool $available): self
    {
        $this->available = $available;

        return $this;
    }

    public function withResult(CliProcessResult $result): self
    {
        $this->result = $result;

        return $this;
    }

    /**
     * @param CliProcessChunk[] $chunks
     */
    public function withChunks(array $chunks): self
    {
        $this->chunks = $chunks;

        return $this;
    }

    public function isAvailable(string $binary): bool
    {
        return $this->available;
    }

    public function run(CliProcessRequest $request): CliProcessResult
    {
        $this->lastRequest = $request;

        return $this->result ?? new CliProcessResult(0, '');
    }

    public function stream(CliProcessRequest $request): iterable
    {
        $this->lastRequest = $request;

        yield from $this->chunks;
    }
}
