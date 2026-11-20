<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Provider for llama.cpp's `llama-server` (its OpenAI-compatible `/v1` API).
 *
 * Start the server with `--jinja` so it applies the model's own chat template and
 * parses its native tool calls; tool calls, reasoning and text then stream per token,
 * and closing the stream stops generation on the server. No API key is needed unless
 * the server runs with `--api-key`.
 *
 * Differences from OpenAI that this class handles:
 *   - chat() reads reasoning from `message.reasoning_content`.
 *   - Streaming falls back to the final chunk's `timings` for usage when the caller
 *     turns `stream_options.include_usage` off (it is on by default).
 *   - models() reads the served context size from `/v1/models` `data[].meta.n_ctx`.
 *
 * Discovered models are labelled `llama-server` (or `$discoveredProviderName`);
 * ProviderFactory resolves `llama-server/<model>` as an alias of `llama-cpp`, and labels
 * the models of a provider it made with the config's provider name, so a discovered
 * `provider/model` string round-trips through the factory.
 */
final class LlamaServerProvider extends OpenAICompatibleProvider
{
    public function __construct(
        string $model,
        string $baseUrl = 'http://localhost:8080/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        string $discoveredProviderName = 'llama-server',
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            logger: $logger,
            discoveredProviderName: $discoveredProviderName,
        );
    }

    /**
     * Usage from the chunk's `usage`, else from llama-server's `timings`, which it
     * adds to the last chunk of every stream. Prompt tokens are `prompt_n + cache_n`,
     * as in its own `usage`.
     */
    #[\Override]
    protected function streamChunkUsage(array $json): ?Usage
    {
        $usage = parent::streamChunkUsage($json);
        $timings = $json['timings'] ?? null;
        if ($usage !== null || !is_array($timings)) {
            return $usage;
        }

        $processed = $timings['prompt_n'] ?? null;
        $cached = $timings['cache_n'] ?? null;
        $predicted = $timings['predicted_n'] ?? null;
        if (!is_int($processed) || !is_int($cached) || !is_int($predicted)) {
            return null;
        }

        return new Usage(
            promptTokens: $processed + $cached,
            completionTokens: $predicted,
            totalTokens: $processed + $cached + $predicted,
            cacheReadTokens: $cached,
        );
    }

    /**
     * llama-server returns reasoning in `message.reasoning_content`, not `thinking`.
     */
    #[\Override]
    protected function parseResponse(array $data): Response
    {
        $reasoning = $data['choices'][0]['message']['reasoning_content'] ?? null;
        if (is_string($reasoning) && !isset($data['choices'][0]['message']['thinking'])) {
            $data['choices'][0]['message']['thinking'] = $reasoning;
        }

        return parent::parseResponse($data);
    }

    /**
     * llama-server reports the context it serves as `meta.n_ctx`.
     */
    #[\Override]
    protected function buildDiscoveredModelDefinition(array $model): ?ModelDefinition
    {
        $contextSize = $model['meta']['n_ctx'] ?? null;
        if (is_int($contextSize) && $contextSize > 0 && !isset($model['context_length'])) {
            $model['context_length'] = $contextSize;
        }

        return parent::buildDiscoveredModelDefinition($model);
    }
}
