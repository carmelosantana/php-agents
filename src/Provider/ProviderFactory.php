<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\LocalModelRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ReasoningEffort;
use CarmeloSantana\PHPAgents\Provider\Cli\ClaudeCliVendorAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates provider instances from OpenClaw-style model strings.
 *
 * Routes to the correct provider class based on provider name, config,
 * and model auto-detection. Supports explicit API selection via the
 * `api` field in provider config (e.g. `"api": "openai-responses"`).
 */
final class ProviderFactory
{
    /**
     * Provider descriptors keyed by canonical provider name.
     *
     * Centralizes aliases, environment variable names, default base URLs,
     * and construction strategy so provider routing lives in one place.
     *
     * @return array<string, array{
     *     aliases?: list<string>,
     *     apiKeyEnvVar?: string|null,
     *     defaultBaseUrl: string,
    *     type: 'anthropic'|'cli'|'gemini'|'llama-cpp'|'mistral'|'ollama'|'openai-compatible'|'xai'
     * }>
     */
    private static function providerDescriptors(): array
    {
        return [
            'ollama' => [
                'apiKeyEnvVar' => null,
                'defaultBaseUrl' => 'http://localhost:11434/v1',
                'type' => 'ollama',
            ],
            'llama-cpp' => [
                'aliases' => ['llamacpp'],
                'apiKeyEnvVar' => null,
                'defaultBaseUrl' => '',
                'type' => 'llama-cpp',
            ],
            'openai' => [
                'apiKeyEnvVar' => 'OPENAI_API_KEY',
                'defaultBaseUrl' => 'https://api.openai.com/v1',
                'type' => 'openai-compatible',
            ],
            'anthropic' => [
                'apiKeyEnvVar' => 'ANTHROPIC_API_KEY',
                'defaultBaseUrl' => 'https://api.anthropic.com/v1',
                'type' => 'anthropic',
            ],
            'openrouter' => [
                'apiKeyEnvVar' => 'OPENROUTER_API_KEY',
                'defaultBaseUrl' => 'https://openrouter.ai/api/v1',
                'type' => 'openai-compatible',
            ],
            'xai' => [
                'apiKeyEnvVar' => 'XAI_API_KEY',
                'defaultBaseUrl' => 'https://api.x.ai/v1',
                'type' => 'xai',
            ],
            'gemini' => [
                'aliases' => ['google'],
                'apiKeyEnvVar' => 'GEMINI_API_KEY',
                'defaultBaseUrl' => 'https://generativelanguage.googleapis.com/v1beta',
                'type' => 'gemini',
            ],
            'mistral' => [
                'apiKeyEnvVar' => 'MISTRAL_API_KEY',
                'defaultBaseUrl' => 'https://api.mistral.ai/v1',
                'type' => 'mistral',
            ],
            'minimax' => [
                'apiKeyEnvVar' => 'MINIMAX_API_KEY',
                'defaultBaseUrl' => 'https://api.minimaxi.com/v1',
                'type' => 'openai-compatible',
            ],
            'claude-cli' => [
                'aliases' => ['claudecli'],
                'apiKeyEnvVar' => null,
                'defaultBaseUrl' => '',
                'type' => 'cli',
            ],
        ];
    }

    public function __construct(
        private readonly ?ConfigInterface $config = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?LocalModelRuntimeInterface $localModelRuntime = null,
        private readonly ?CliRuntimeInterface $cliRuntime = null,
    ) {}

    /**
     * Create a provider from a model string using injected config.
     *
     * Preferred over the static method when you have a factory instance,
     * since the config is already bound and doesn't need to be passed each time.
     */
    public function create(string $modelString): ProviderInterface
    {
        return self::fromModelString($modelString, $this->config, $this->httpClient, $this->localModelRuntime, $this->cliRuntime);
    }

    /**
     * Create a provider from an OpenClaw-style model string.
     *
     * @param string $modelString e.g., "ollama/llama3.2:latest"
     * @param ConfigInterface|null $config OpenClaw config for baseUrl/apiKey lookups
     */

    public static function fromModelString(
        string $modelString,
        ?ConfigInterface $config = null,
        ?HttpClientInterface $httpClient = null,
        ?LocalModelRuntimeInterface $localModelRuntime = null,
        ?CliRuntimeInterface $cliRuntime = null,
    ): ProviderInterface {
        [$requestedProviderName, $model] = self::parseModelString($modelString);

        $descriptor = self::resolveProviderDescriptor($requestedProviderName);
        $providerName = $descriptor['name'];

        $providerConfig = self::resolveProviderConfig($config, $requestedProviderName, $providerName);
        $baseUrl = self::resolveBaseUrl($descriptor, $providerConfig);
        $apiKey = self::resolveApiKey($descriptor, $providerConfig);

        $api = is_string($providerConfig['api'] ?? null) ? $providerConfig['api'] : null;

        return match ($descriptor['type']) {
            'ollama' => self::makeOllamaProvider($model, $baseUrl, $config, $httpClient),
            'llama-cpp' => self::makeLlamaCppProvider($model, $providerConfig, $config, $localModelRuntime),
            'cli' => self::makeCliProvider($model, $providerName, $providerConfig, $cliRuntime),
            'anthropic' => new AnthropicProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
            ),
            'gemini' => new GeminiProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
            ),
            'xai' => new XAIProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
            ),
            'mistral' => new MistralProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
            ),
            default => self::makeOpenAICompatibleProvider($model, $baseUrl, $apiKey, $api, $providerName, $httpClient),
        };
    }

    /**
     * Resolve a provider descriptor, applying aliases to canonical names.
     *
     * @return array{
     *     name: string,
     *     apiKeyEnvVar?: string|null,
     *     defaultBaseUrl: string,
    *     type: 'anthropic'|'cli'|'gemini'|'llama-cpp'|'mistral'|'ollama'|'openai-compatible'|'xai'
     * }
     */
    private static function resolveProviderDescriptor(string $providerName): array
    {
        foreach (self::providerDescriptors() as $canonicalName => $descriptor) {
            $aliases = $descriptor['aliases'] ?? [];
            if ($providerName === $canonicalName || in_array($providerName, $aliases, true)) {
                return ['name' => $canonicalName] + $descriptor;
            }
        }

        return [
            'name' => $providerName,
            'apiKeyEnvVar' => strtoupper($providerName) . '_API_KEY',
            'defaultBaseUrl' => '',
            'type' => 'openai-compatible',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveProviderConfig(
        ?ConfigInterface $config,
        string $requestedProviderName,
        string $canonicalProviderName,
    ): array {
        $requestedConfig = $config?->getProviderConfig($requestedProviderName) ?? [];
        if ($requestedConfig !== []) {
            return $requestedConfig;
        }

        if ($canonicalProviderName !== $requestedProviderName) {
            $canonicalConfig = $config?->getProviderConfig($canonicalProviderName) ?? [];
            return $canonicalConfig;
        }

        return [];
    }

    /**
     * Construct an OllamaProvider, optionally reading per-model numCtx from config.
     *
     * Looks up the model definition in the config to extract an overridden numCtx value.
     * This allows small-VRAM models (e.g. ministral-3:3b) to declare a tighter context
     * window in openclaw.json while larger models keep the 65536 default.
     */
    private static function makeOllamaProvider(
        string $model,
        string $baseUrl,
        ?ConfigInterface $config,
        ?HttpClientInterface $httpClient = null,
    ): OllamaProvider {
        // Definitions may be keyed by bare id or by "provider/id"
        // (OpenClaw config uses the prefixed form) — try both.
        $modelDef = $config?->getModelDefinition($model)
            ?? $config?->getModelDefinition("ollama/{$model}");
        $numCtx = $modelDef?->numCtx;

        $args = [
            'model' => $model,
            'baseUrl' => $baseUrl,
        ];

        if ($numCtx !== null) {
            $args['numCtx'] = $numCtx;
        }

        // Per-model reasoning control (e.g. `"reasoningEffort": "none"` to
        // disable thinking). Invalid values are ignored rather than fatal.
        $effort = $modelDef?->extras['reasoningEffort'] ?? null;
        if (is_string($effort)) {
            $parsed = ReasoningEffort::tryFrom(strtolower($effort));
            if ($parsed !== null) {
                $args['reasoningEffort'] = $parsed;
            }
        }

        if ($httpClient !== null) {
            $args['httpClient'] = $httpClient;
        }

        return new OllamaProvider(...$args);
    }

    /**
     * @param array<string, mixed> $providerConfig
     */
    private static function makeLlamaCppProvider(
        string $model,
        array $providerConfig,
        ?ConfigInterface $config,
        ?LocalModelRuntimeInterface $localModelRuntime,
    ): LlamaCppProvider {
        if ($localModelRuntime === null) {
            throw new \InvalidArgumentException('llama-cpp provider requires an injected local model runtime.');
        }

        $modelDefinition = $config?->getModelDefinition($model);
        $runtimeOptions = $providerConfig;

        if (is_string($providerConfig['defaultTemplate'] ?? null) && !isset($runtimeOptions['builtInTemplate'])) {
            $runtimeOptions['builtInTemplate'] = $providerConfig['defaultTemplate'];
        }

        if (is_string($providerConfig['defaultToolParser'] ?? null) && !isset($runtimeOptions['defaultToolParser'])) {
            $runtimeOptions['defaultToolParser'] = $providerConfig['defaultToolParser'];
        }

        if ($modelDefinition?->numCtx !== null && !isset($runtimeOptions['numCtx'])) {
            $runtimeOptions['numCtx'] = $modelDefinition->numCtx;
        }

        $modelTemplate = $modelDefinition?->extras['template'] ?? null;
        if (is_string($modelTemplate) && $modelTemplate !== '' && !isset($runtimeOptions['modelTemplate'])) {
            $runtimeOptions['modelTemplate'] = $modelTemplate;
        }

        $modelToolParser = $modelDefinition?->extras['toolParser'] ?? null;
        if (is_string($modelToolParser) && $modelToolParser !== '' && !isset($runtimeOptions['modelToolParser'])) {
            $runtimeOptions['modelToolParser'] = $modelToolParser;
        }

        $modelProjectorPath = $modelDefinition?->extras['projectorPath'] ?? null;
        if (is_string($modelProjectorPath) && $modelProjectorPath !== '' && !isset($runtimeOptions['modelProjectorPath'])) {
            $runtimeOptions['modelProjectorPath'] = $modelProjectorPath;
        }

        $maxImages = $modelDefinition?->extras['maxImages'] ?? null;
        if (is_int($maxImages) && !isset($runtimeOptions['maxImages'])) {
            $runtimeOptions['maxImages'] = $maxImages;
        }

        $imageTokenCost = $modelDefinition?->extras['imageTokenCost'] ?? null;
        if (is_int($imageTokenCost) && !isset($runtimeOptions['imageTokenCost'])) {
            $runtimeOptions['imageTokenCost'] = $imageTokenCost;
        }

        $structuredOutputModes = $modelDefinition?->extras['structuredOutputModes'] ?? null;
        if (is_array($structuredOutputModes) && !isset($runtimeOptions['structuredOutputModes'])) {
            $runtimeOptions['structuredOutputModes'] = $structuredOutputModes;
        }

        $supportsStructuredOutput = $modelDefinition?->extras['supportsStructuredOutput'] ?? null;
        if (is_bool($supportsStructuredOutput) && !isset($runtimeOptions['supportsStructuredOutput'])) {
            $runtimeOptions['supportsStructuredOutput'] = $supportsStructuredOutput;
        }

        return new LlamaCppProvider(
            model: $model,
            runtime: $localModelRuntime,
            runtimeOptions: $runtimeOptions,
        );
    }

    /**
     * Construct a CLI-backed provider, selecting a vendor adapter by provider name.
     *
     * The adapter is the expandable seam: new CLI vendors (codex, grok, ...) map
     * to their own adapter here without a new provider class. The host app must
     * inject a CliRuntimeInterface so php-agents itself never spawns a process.
     *
     * @param array<string, mixed> $providerConfig
     */
    private static function makeCliProvider(
        string $model,
        string $providerName,
        array $providerConfig,
        ?CliRuntimeInterface $cliRuntime,
    ): CliProvider {
        if ($cliRuntime === null) {
            throw new \InvalidArgumentException('cli provider requires an injected CLI runtime.');
        }

        $binary = is_string($providerConfig['binary'] ?? null) && $providerConfig['binary'] !== ''
            ? $providerConfig['binary']
            : 'claude';

        $adapter = match ($providerName) {
            // Future vendors: 'codex' => new CodexCliVendorAdapter($binary), etc.
            default => new ClaudeCliVendorAdapter($binary),
        };

        return new CliProvider(
            model: $model,
            adapter: $adapter,
            runtime: $cliRuntime,
        );
    }

    private static function makeOpenAICompatibleProvider(
        string $model,
        string $baseUrl,
        string $apiKey,
        ?string $api,
        string $providerName,
        ?HttpClientInterface $httpClient = null,
    ): ProviderInterface {
        return match (true) {
            $api === 'openai-responses' => new OpenAIResponsesProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
                discoveredProviderName: $providerName,
            ),
            self::requiresResponsesApi($model) => new OpenAIResponsesProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
                discoveredProviderName: $providerName,
            ),
            default => new OpenAICompatibleProvider(
                model: $model,
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
                discoveredProviderName: $providerName,
            ),
        };
    }

    /**
     * Parse "provider/model-name" into [provider, model].
     *
     * @return array{0: string, 1: string}
     */
    public static function parseModelString(string $modelString): array
    {
        $slash = strpos($modelString, '/');

        if ($slash === false) {
            return ['ollama', $modelString];
        }

        return [
            substr($modelString, 0, $slash),
            substr($modelString, $slash + 1),
        ];
    }

    /**
     * Resolve base URL with environment variable overrides.
     *
     * Supports OLLAMA_HOST env var for Docker/container environments.
     *
    * @param array{
    *     name: string,
    *     apiKeyEnvVar?: string|null,
    *     defaultBaseUrl: string,
    *     type: 'anthropic'|'cli'|'gemini'|'llama-cpp'|'mistral'|'ollama'|'openai-compatible'|'xai'
    * } $descriptor
     * @param array<string, mixed> $providerConfig
     */
    private static function resolveBaseUrl(array $descriptor, array $providerConfig): string
    {
        $provider = $descriptor['name'];
        if ($provider === 'ollama') {
            $envHost = getenv('OLLAMA_HOST');
            if ($envHost !== false && $envHost !== '') {
                return rtrim($envHost, '/') . '/v1';
            }
        }

        $baseUrl = $providerConfig['baseUrl'] ?? null;

        return is_string($baseUrl) ? $baseUrl : $descriptor['defaultBaseUrl'];
    }

    /**
     * Resolve API key with environment variable override.
     *
     * Priority: getenv(PROVIDER_API_KEY) > config apiKey > empty string.
     * This allows .env files to override hardcoded config values, and
     * enables Coqui's CredentialTool to manage provider keys at runtime.
     *
    * @param array{
    *     name: string,
    *     apiKeyEnvVar?: string|null,
    *     defaultBaseUrl: string,
    *     type: 'anthropic'|'cli'|'gemini'|'llama-cpp'|'mistral'|'ollama'|'openai-compatible'|'xai'
    * } $descriptor
     * @param array<string, mixed> $providerConfig
     */
    private static function resolveApiKey(array $descriptor, array $providerConfig): string
    {
        $envVar = $descriptor['apiKeyEnvVar'] ?? null;

        if (is_string($envVar) && $envVar !== '') {
            $envValue = getenv($envVar);
            if ($envValue !== false && $envValue !== '') {
                return $envValue;
            }
        }

        $configKey = $providerConfig['apiKey'] ?? '';

        return is_string($configKey) ? $configKey : '';
    }

    /**
     * Detect models that require the OpenAI Responses API.
     *
     * Codex models (gpt-5-codex, etc.) return 404 on /v1/chat/completions
     * and must be routed through /v1/responses instead.
     */
    private static function requiresResponsesApi(string $model): bool
    {
        return str_contains(strtolower($model), 'codex');
    }
}
