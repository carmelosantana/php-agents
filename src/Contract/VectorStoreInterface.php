<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Memory\Document;

/**
 * Contract for vector similarity search backends.
 */
interface VectorStoreInterface
{
    /**
     * Add a single document (must have embedding set).
     */
    public function addDocument(Document $document): void;

    /**
     * Add multiple documents in batch.
     *
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): void;

    /**
     * Similarity search by embedding vector.
     *
     * @param float[] $embedding Query embedding
     * @return Document[] Ranked by similarity (highest first)
     */
    public function similaritySearch(array $embedding, int $limit = 10, float $threshold = 0.0): array;

    /**
     * Delete documents by their IDs.
     *
     * @param string[] $ids
     */
    public function deleteByIds(array $ids): void;

    /**
     * Delete all documents matching a source.
     */
    public function deleteBySource(string $sourceType, string $sourceName): void;
}
