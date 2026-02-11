<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Memory\Document;

/**
 * Contract for text to vector embedding.
 */
interface EmbeddingProviderInterface
{
    /**
     * Embed a single text string.
     *
     * @return float[] Vector embedding
     */
    public function embedText(string $text): array;

    /**
     * Embed a document.
     */
    public function embedDocument(Document $document): Document;

    /**
     * Embed multiple documents in batch.
     *
     * @param Document[] $documents
     * @return Document[] Documents with embeddings
     */
    public function embedDocuments(array $documents): array;

    /**
     * The embedding model identifier.
     */
    public function model(): string;

    /**
     * Vector dimension size produced by this provider.
     */
    public function dimensions(): int;
}
