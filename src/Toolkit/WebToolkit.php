<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebToolkit implements ToolkitInterface
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly ?string $searchEndpoint = null,
        private readonly ?string $searchApiKey = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 30]);
    }

    public function tools(): array
    {
        $tools = [$this->httpRequestTool()];

        if ($this->searchEndpoint !== null) {
            $tools[] = $this->webSearchTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        return <<<GUIDELINES
        <WEB-GUIDELINES>
        - Use http_request to fetch web pages or call APIs.
        - Use web_search for information discovery.
        - Respect rate limits and robots.txt.
        - Prefer structured APIs over scraping when available.
        </WEB-GUIDELINES>
        GUIDELINES;
    }

    private function httpRequestTool(): ToolInterface
    {
        return new Tool(
            name: 'http_request',
            description: 'Make an HTTP request to a URL.',
            parameters: [
                new StringParameter('url', 'The URL to request'),
                new EnumParameter('method', 'HTTP method', ['GET', 'POST', 'PUT', 'DELETE'], required: false),
                new StringParameter('body', 'Request body (for POST/PUT)', required: false),
                new StringParameter('headers', 'JSON object of headers', required: false),
            ],
            callback: function (array $input): ToolResult {
                $url = $input['url'] ?? '';
                $method = $input['method'] ?? 'GET';
                $body = $input['body'] ?? null;
                $headersJson = $input['headers'] ?? '{}';

                if ($url === '') {
                    return ToolResult::error('URL is required');
                }

                try {
                    $options = [];
                    $headers = json_decode($headersJson, true) ?? [];

                    if (!empty($headers)) {
                        $options['headers'] = $headers;
                    }

                    if ($body !== null && in_array($method, ['POST', 'PUT'])) {
                        $options['body'] = $body;
                    }

                    $response = $this->httpClient->request($method, $url, $options);
                    $content = $response->getContent(false);
                    $statusCode = $response->getStatusCode();

                    $result = [
                        'status' => $statusCode,
                        'content' => mb_substr($content, 0, 10000),
                    ];

                    if (strlen($content) > 10000) {
                        $result['truncated'] = true;
                        $result['total_length'] = strlen($content);
                    }

                    return ToolResult::success(json_encode($result, JSON_PRETTY_PRINT) ?: '');
                } catch (\Throwable $e) {
                    return ToolResult::error("HTTP request failed: {$e->getMessage()}");
                }
            },
        );
    }

    private function webSearchTool(): ToolInterface
    {
        return new Tool(
            name: 'web_search',
            description: 'Search the web for information.',
            parameters: [
                new StringParameter('query', 'Search query'),
            ],
            callback: function (array $input): ToolResult {
                $query = $input['query'] ?? '';

                if ($query === '' || $this->searchEndpoint === null) {
                    return ToolResult::error('Search query is required');
                }

                try {
                    $headers = [];
                    if ($this->searchApiKey !== null) {
                        $headers['Authorization'] = "Bearer {$this->searchApiKey}";
                    }

                    $response = $this->httpClient->request('GET', $this->searchEndpoint, [
                        'query' => ['q' => $query],
                        'headers' => $headers,
                    ]);

                    return ToolResult::success($response->getContent());
                } catch (\Throwable $e) {
                    return ToolResult::error("Search failed: {$e->getMessage()}");
                }
            },
        );
    }
}
