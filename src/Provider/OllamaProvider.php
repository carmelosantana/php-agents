<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OllamaProvider extends OpenAICompatibleProvider
{
    public function __construct(
        string $model = 'llama3.2',
        string $baseUrl = 'http://localhost:11434/v1',
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: 'ollama-local',
            httpClient: $httpClient,
        );
    }

    /**
     * List locally available models via Ollama's native API.
     *
     * @return ModelDefinition[]
     */
    public function models(): array
    {
        try {
            $ollamaBaseUrl = str_replace('/v1', '', $this->baseUrl);
            $response = $this->httpClient->request('GET', "{$ollamaBaseUrl}/api/tags");
            $data = $response->toArray();

            $models = [];
            foreach ($data['models'] ?? [] as $model) {
                $models[] = new ModelDefinition(
                    id: $model['name'] ?? '',
                    name: $model['name'] ?? '',
                    provider: 'ollama',
                );
            }

            return $models;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pull a model from Ollama registry.
     */
    public function pull(string $model): void
    {
        $ollamaBaseUrl = str_replace('/v1', '', $this->baseUrl);
        $this->httpClient->request('POST', "{$ollamaBaseUrl}/api/pull", [
            'json' => ['name' => $model],
        ]);
    }

    /**
     * Check if a specific model is available locally.
     */
    public function hasModel(string $model): bool
    {
        $models = $this->models();

        foreach ($models as $m) {
            if ($m->id === $model || str_starts_with($m->id, $model)) {
                return true;
            }
        }

        return false;
    }

    public function isAvailable(): bool
    {
        try {
            $ollamaBaseUrl = str_replace('/v1', '', $this->baseUrl);
            $this->httpClient->request('GET', "{$ollamaBaseUrl}/api/tags", [
                'timeout' => 5,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
