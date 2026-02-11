<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Memory;

final readonly class EmbeddingResult
{
    /**
     * @param float[] $embedding The vector embedding
     */
    public function __construct(
        public array $embedding,
        public string $model,
        public int $dimensions,
        public int $tokenCount = 0,
    ) {}
}
