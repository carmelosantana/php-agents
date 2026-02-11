<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Embedding;

use CarmeloSantana\PHPAgents\Contract\EmbeddingProviderInterface;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Memory\Document;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $modelName = 'text-embedding-3-small',
        private readonly string $apiKey = '',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 60]);
    }

    public function embedText(string $text): array
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/embeddings", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->modelName,
                    'input' => $text,
                ],
            ]);

            $data = $response->toArray();

            return $data['data'][0]['embedding'] ?? [];
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
        $texts = array_map(fn(Document $d) => $d->content, $documents);

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/embeddings", [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->modelName,
                'input' => $texts,
            ],
        ]);

        $data = $response->toArray();
        $results = [];

        foreach ($documents as $index => $document) {
            $embedding = $data['data'][$index]['embedding'] ?? [];
            $document->setEmbedding($embedding);
            $results[] = $document;
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
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => 1536,
        };
    }
}
