<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractProvider implements ProviderInterface
{
    protected const int OPENAI_MAX_TOOLS = 128;

    protected string $model;
    protected string $baseUrl;
    protected string $apiKey;
    protected HttpClientInterface $httpClient;
    protected ?LoggerInterface $logger;

    public function __construct(
        string $model,
        string $baseUrl,
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 300]);
        $this->logger = $logger;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /**
     * Build HTTP headers for API requests.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->apiKey !== '') {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        return $headers;
    }

    /**
     * Trim a provider tool list to a supported maximum and emit warning telemetry.
     *
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function trimToolsToLimit(array $tools, int $maxTools, string $provider, string $endpoint): array
    {
        if (count($tools) <= $maxTools) {
            return $tools;
        }

        $trimmed = array_slice($tools, 0, $maxTools);

        $this->logger?->warning(
            '{provider} tool list exceeded the maximum supported size for {endpoint}; trimming tools before sending the request.',
            [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'tool_count' => count($tools),
                'tool_limit' => $maxTools,
                'trimmed_count' => count($trimmed),
            ],
        );

        return $trimmed;
    }

    /**
     * Convert ToolInterface[] to provider-specific tool definitions.
     *
     * @param ToolInterface[] $tools
     * @return array<array<string, mixed>>
     */
    abstract protected function formatTools(array $tools): array;

    /**
     * Convert MessageInterface[] to provider-specific message format.
     *
     * @param MessageInterface[] $messages
     * @return array<array<string, mixed>>
     */
    abstract protected function formatMessages(array $messages): array;

    /**
     * Parse provider response into a Response value object.
     *
     * @param array<string, mixed> $data
     */
    abstract protected function parseResponse(array $data): Response;
}
