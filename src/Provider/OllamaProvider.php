<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ReasoningEffort;
use CarmeloSantana\PHPAgents\Provider\Response;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OllamaProvider extends OpenAICompatibleProvider
{
    /**
     * JSON Schema keywords unsupported by Ollama's tool parser.
     *
     * Ollama's Go backend maps tool schemas to strict internal structs.
     * Keywords not in those structs cause 400 errors or silently break
     * property parsing. We strip these before sending and demote
     * validation constraints into the description field.
     */
    private const UNSUPPORTED_SCHEMA_KEYWORDS = [
        // Validation keywords
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'minLength',
        'maxLength',
        'pattern',
        'additionalProperties',
        'minItems',
        'maxItems',
        'uniqueItems',
        'format',
        // Structural / logic keywords
        'oneOf',
        'anyOf',
        'allOf',
        'const',
        '$ref',
        '$defs',
        'patternProperties',
        'default',
    ];

    /**
     * Keywords whose values can be demoted into the description field
     * so the LLM still sees the constraint as natural language.
     */
    private const DEMOTABLE_KEYWORDS = [
        'minimum' => 'Minimum value: %s.',
        'maximum' => 'Maximum value: %s.',
        'exclusiveMinimum' => 'Must be greater than %s.',
        'exclusiveMaximum' => 'Must be less than %s.',
        'minLength' => 'Minimum length: %s.',
        'maxLength' => 'Maximum length: %s.',
        'pattern' => 'Must match pattern: %s.',
        'minItems' => 'Minimum items: %s.',
        'maxItems' => 'Maximum items: %s.',
        'const' => 'Must be exactly: %s.',
        'default' => 'Default: %s.',
        'format' => 'Format: %s.',
    ];

    /**
     * Default context window size for Ollama when tools are present.
     *
     * Tool schemas can easily consume 30-50K tokens. Ollama defaults
     * to 8192 which causes silent truncation and 500 errors.
     */
    private const DEFAULT_NUM_CTX = 65536;

    public function __construct(
        string $model = 'llama3.2',
        string $baseUrl = 'http://localhost:11434/v1',
        ?HttpClientInterface $httpClient = null,
        private int $numCtx = self::DEFAULT_NUM_CTX,
        ?LoggerInterface $logger = null,
        private ?ReasoningEffort $reasoningEffort = null,
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: 'ollama-local',
            httpClient: $httpClient,
            logger: $logger,
        );
    }

    /**
     * Override chat to inject Ollama-specific options.
     *
     * Sets num_ctx to ensure Ollama allocates enough context for tool
     * schemas. Without this, Ollama defaults to 8192 tokens and silently
     * truncates the prompt, causing corrupted tool definitions and 500s.
     */
    #[\Override]
    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        return parent::chat($messages, $tools, $this->injectOllamaOptions($options, $tools));
    }

    /**
     * Override stream to inject Ollama-specific options.
     */
    #[\Override]
    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        return parent::stream($messages, $tools, $this->injectOllamaOptions($options, $tools));
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
                if (!is_array($model)) {
                    continue;
                }

                $id = $model['name'] ?? '';
                if (!is_string($id) || $id === '') {
                    continue;
                }

                $show = $this->fetchModelDetails($id);
                $definition = $this->buildModelDefinition($model, $show);
                if ($definition !== null) {
                    $models[] = $definition;
                }
            }

            return $models;
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch Ollama models: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchModelDetails(string $model): ?array
    {
        try {
            $ollamaBaseUrl = str_replace('/v1', '', $this->baseUrl);
            $response = $this->httpClient->request('POST', "{$ollamaBaseUrl}/api/show", [
                'json' => ['name' => $model],
                'timeout' => 10,
            ]);

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch Ollama model details: {model} {error}', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $tagData
     * @param array<string, mixed>|null $showData
     */
    private function buildModelDefinition(array $tagData, ?array $showData): ?ModelDefinition
    {
        $id = $tagData['name'] ?? '';
        if (!is_string($id) || $id === '') {
            return null;
        }

        $modelInfo = is_array($showData['model_info'] ?? null) ? $showData['model_info'] : [];
        $architecture = $modelInfo['general.architecture'] ?? null;
        $contextLength = null;
        if (is_string($architecture) && isset($modelInfo[$architecture . '.context_length'])) {
            $value = $modelInfo[$architecture . '.context_length'];
            if (is_int($value) || (is_string($value) && is_numeric($value))) {
                $contextLength = (int) $value;
            }
        }

        $capabilities = is_array($showData['capabilities'] ?? null) ? $showData['capabilities'] : [];
        $details = is_array($tagData['details'] ?? null)
            ? $tagData['details']
            : (is_array($showData['details'] ?? null) ? $showData['details'] : []);

        return ModelDefinition::fromDiscovery('ollama', [
            'id' => $id,
            'name' => $modelInfo['general.basename'] ?? $showData['remote_model'] ?? $id,
            'details' => $details,
            'contextWindow' => $contextLength,
            'maxTokens' => $contextLength !== null
                ? ($contextLength < 4096 ? max(1024, (int) floor($contextLength / 2)) : 4096)
                : null,
            'vision' => in_array('vision', $capabilities, true),
            'toolCalls' => in_array('tools', $capabilities, true),
            'metadataSource' => $contextLength !== null ? 'provider-inspection' : 'heuristic',
            'fieldSources' => [
                'contextWindow' => $contextLength !== null ? 'provider-inspection' : 'heuristic',
                'maxTokens' => $contextLength !== null ? 'heuristic' : 'heuristic',
            ],
            'numCtx' => $contextLength,
        ]);
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
            if (self::matchesModelId($m->id, $model)) {
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
        } catch (\Throwable $e) {
            $this->logger?->debug('Ollama availability check failed: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Inject Ollama-specific options into the request payload.
     *
     * - Suppresses `stream_options` (not supported by Ollama's OpenAI-compat endpoint).
     * - Sets `num_ctx` at the top level, which is the format supported by Ollama's
     *   OpenAI-compatible endpoint (`/v1/chat/completions`). The nested `options.num_ctx`
     *   format is for the native API (`/api/chat`) only and is silently ignored here.
     *
     * @param array<string, mixed> $options
     * @param array<ToolInterface> $tools
     * @return array<string, mixed>
     */
    private function injectOllamaOptions(array $options, array $tools): array
    {
        // Ollama 0.5+ supports stream_options.include_usage for token reporting.
        // Allow the parent OpenAICompatibleProvider to send it (default behavior).

        if (!empty($tools) && !isset($options['num_ctx'])) {
            $options['num_ctx'] = $this->numCtx;
        }

        // Only send reasoning_effort when explicitly configured — Ollama's
        // strict request structs and non-thinking models should never see
        // the field. `none` disables thinking on thinking-capable models.
        if ($this->reasoningEffort !== null && !isset($options['reasoning_effort'])) {
            $options['reasoning_effort'] = $this->reasoningEffort->value;
        }

        return $options;
    }

    /**
     * Match a requested model ID without broad prefix expansion.
     *
     * Ollama commonly treats an untagged model name as an alias for
     * `:latest`, so preserve that compatibility case while rejecting
     * arbitrary prefix matches such as `gpt-5.4` -> `gpt-5.4-2026-03-25`.
     */
    private static function matchesModelId(string $availableModelId, string $requestedModelId): bool
    {
        if ($availableModelId === $requestedModelId) {
            return true;
        }

        [$availableBase, $availableTag] = self::splitModelId($availableModelId);
        [$requestedBase, $requestedTag] = self::splitModelId($requestedModelId);

        if ($availableBase !== $requestedBase) {
            return false;
        }

        return ($availableTag === 'latest' && $requestedTag === null)
            || ($requestedTag === 'latest' && $availableTag === null);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function splitModelId(string $modelId): array
    {
        $parts = explode(':', $modelId, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * Format tools for Ollama, stripping unsupported JSON Schema keywords.
     *
     * Ollama's tool schema parser only supports a subset of JSON Schema.
     * We generate full schemas via toFunctionSchema() then recursively
     * remove keywords that would cause 400 "invalid tool call arguments".
     */
    #[\Override]
    protected function formatTools(array $tools): array
    {
        return array_map(function (ToolInterface $tool): array {
            $schema = $tool->toFunctionSchema();

            if (isset($schema['function']['parameters'])) {
                $schema['function']['parameters'] = $this->sanitizeSchema(
                    $schema['function']['parameters'],
                );
            }

            return $schema;
        }, $tools);
    }

    /**
     * Recursively sanitize a schema node for Ollama compatibility.
     *
     * Demotes validation constraints into the description field,
     * flattens union combinators to the first concrete type, then
     * strips all remaining unsupported keywords.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function sanitizeSchema(array $schema): array
    {
        // Flatten anyOf / oneOf / allOf → pick first non-null type
        foreach (['anyOf', 'oneOf', 'allOf'] as $combinator) {
            if (isset($schema[$combinator]) && is_array($schema[$combinator])) {
                $schema = SchemaUtils::flattenCombinator($schema, $combinator);
            }
        }

        // Demote validation keywords into description, then strip
        $schema = SchemaUtils::demoteConstraints($schema, self::DEMOTABLE_KEYWORDS);
        $schema = SchemaUtils::stripKeywords($schema, self::UNSUPPORTED_SCHEMA_KEYWORDS);

        // Recurse into object properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = $this->sanitizeSchema($property);
                }
            }
        }

        // Recurse into array items
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->sanitizeSchema($schema['items']);
        }

        return $schema;
    }
}
