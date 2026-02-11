<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Embedding;

use CarmeloSantana\PHPAgents\Contract\EmbeddingProviderInterface;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Memory\Document;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $modelName = 'nomic-embed-text',
        private readonly string $baseUrl = 'http://localhost:11434',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 60]);
    }

    public function embedText(string $text): array
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/api/embeddings", [
                'json' => [
                    'model' => $this->modelName,
                    'prompt' => $text,
                ],
            ]);

            $data = $response->toArray();

            return $data['embedding'] ?? [];
        } catch (\Throwable $e) {
            throw ProviderException::embeddingFailed($e->getMessage(), $e);
        }
    }

    public function embedDocument(Document $document): Document
    {
        $embedding = $this->embedText($document->content);
        $document->setEmbedding($embedding);

        return $document;
    }

    public function embedDocuments(array $documents): array
    {
        $results = [];

        foreach ($documents as $document) {
            $results[] = $this->embedDocument($document);
        }

        return $results;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function dimensions(): int
    {
        return match ($this->modelName) {
            'nomic-embed-text' => 768,
            'mxbai-embed-large' => 1024,
            default => 768,
        };
    }
}
