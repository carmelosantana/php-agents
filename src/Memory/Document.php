<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Memory;

final class Document
{
    /** @var float[]|null */
    private ?array $embedding = null;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly string $sourceType = 'memory',
        public readonly string $sourceName = '',
        public readonly array $metadata = [],
        public readonly ?string $id = null,
    ) {}

    /**
     * @param float[] $embedding
     */
    public function withEmbedding(array $embedding): self
    {
        $clone = clone $this;
        $clone->embedding = $embedding;

        return $clone;
    }

    /**
     * @param float[] $embedding
     * @deprecated Use withEmbedding() for immutability
     */
    public function setEmbedding(array $embedding): void
    {
        $this->embedding = $embedding;
    }

    /**
     * @return float[]|null
     */
    public function getEmbedding(): ?array
    {
        return $this->embedding;
    }

    public function hasEmbedding(): bool
    {
        return $this->embedding !== null;
    }
}
