<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
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
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: 'ollama-local',
            httpClient: $httpClient,
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

    /**
     * Inject Ollama-specific options into the request payload.
     *
     * When tools are present, sets num_ctx to ensure Ollama allocates
     * enough KV cache for the full tool schema payload. Without this,
     * Ollama's default 8192 tokens truncates tool definitions mid-schema,
     * causing the model to generate invalid tool calls → 500 errors.
     *
     * @param array<string, mixed> $options
     * @param array<ToolInterface> $tools
     * @return array<string, mixed>
     */
    private function injectOllamaOptions(array $options, array $tools): array
    {
        if (!empty($tools) && !isset($options['num_ctx'])) {
            $options['num_ctx'] = $this->numCtx;
        }

        return $options;
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
                $schema = $this->flattenCombinator($schema, $combinator);
            }
        }

        // Demote validation keywords into description
        $schema = $this->demoteConstraints($schema);

        // Strip everything Ollama doesn't understand
        foreach (self::UNSUPPORTED_SCHEMA_KEYWORDS as $keyword) {
            unset($schema[$keyword]);
        }

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

    /**
     * Flatten a union combinator (anyOf/oneOf/allOf) into a single type.
     *
     * Picks the first non-null variant and merges its fields into the
     * parent schema, so Ollama sees a simple single-type property.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function flattenCombinator(array $schema, string $combinator): array
    {
        /** @var list<array<string, mixed>> $variants */
        $variants = $schema[$combinator];
        unset($schema[$combinator]);

        // Find the first variant that isn't just {type: "null"}
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (($variant['type'] ?? null) === 'null') {
                continue;
            }
            // Merge variant fields into parent (type, description, etc.)
            $schema = array_merge($schema, $variant);
            return $schema;
        }

        // All variants were null — fall back to string
        $schema['type'] = 'string';

        return $schema;
    }

    /**
     * Demote validation constraints into the description field.
     *
     * Before stripping unsupported keywords, append their values as
     * human-readable hints so the LLM still respects the constraints.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function demoteConstraints(array $schema): array
    {
        $hints = [];

        foreach (self::DEMOTABLE_KEYWORDS as $keyword => $template) {
            if (!isset($schema[$keyword])) {
                continue;
            }

            $value = $schema[$keyword];
            $display = is_scalar($value) ? (string) $value : json_encode($value);
            $hints[] = sprintf($template, $display);
        }

        if (!empty($hints)) {
            $existing = $schema['description'] ?? '';
            $suffix = implode(' ', $hints);
            $schema['description'] = $existing !== ''
                ? $existing . ' ' . $suffix
                : $suffix;
        }

        return $schema;
    }
}
