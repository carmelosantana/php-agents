<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\VectorStore;

use CarmeloSantana\PHPAgents\Contract\VectorStoreInterface;
use CarmeloSantana\PHPAgents\Exception\DocumentException;
use CarmeloSantana\PHPAgents\Memory\Document;

final class InMemoryVectorStore implements VectorStoreInterface
{
    /** @var Document[] */
    private array $documents = [];

    public function addDocument(Document $document): void
    {
        if (!$document->hasEmbedding()) {
            throw DocumentException::missingEmbedding();
        }

        $this->documents[] = $document;
    }

    public function addDocuments(array $documents): void
    {
        foreach ($documents as $document) {
            $this->addDocument($document);
        }
    }

    public function similaritySearch(array $embedding, int $limit = 10, float $threshold = 0.0): array
    {
        $scores = [];

        foreach ($this->documents as $index => $document) {
            $docEmbedding = $document->getEmbedding();
            if ($docEmbedding === null) {
                continue;
            }

            $score = $this->cosineSimilarity($embedding, $docEmbedding);

            if ($score >= $threshold) {
                $scores[$index] = $score;
            }
        }

        arsort($scores);

        $results = [];
        $count = 0;

        foreach ($scores as $index => $score) {
            if ($count >= $limit) {
                break;
            }

            $results[] = $this->documents[$index];
            $count++;
        }

        return $results;
    }

    public function deleteByIds(array $ids): void
    {
        $this->documents = array_filter(
            $this->documents,
            fn(Document $doc) => !in_array($doc->id, $ids, true),
        );
        $this->documents = array_values($this->documents);
    }

    public function deleteBySource(string $sourceType, string $sourceName): void
    {
        $this->documents = array_filter(
            $this->documents,
            fn(Document $doc) => $doc->sourceType !== $sourceType || $doc->sourceName !== $sourceName,
        );
        $this->documents = array_values($this->documents);
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || count($a) === 0) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }
}
