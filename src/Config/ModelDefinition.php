<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Config;

use CarmeloSantana\PHPAgents\Enum\ModelCapability;

final readonly class ModelDefinition
{
    /**
     * @param ModelCapability[] $capabilities
     * @param array<string, string> $fieldSources
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $provider,
        public array $capabilities = [ModelCapability::Text],
        public bool $reasoning = false,
        public int $contextWindow = 4096,
        public int $maxTokens = 2048,
        public ?string $alias = null,
        public ?int $numCtx = null,
        public ?string $family = null,
        public bool $toolCalls = false,
        public bool $vision = false,
        public bool $thinking = false,
        public ?string $metadataSource = null,
        public array $fieldSources = [],
        public array $extras = [],
    ) {}

    public function supports(ModelCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function supportsVision(): bool
    {
        return $this->vision || $this->supports(ModelCapability::Image);
    }

    public function supportsToolCalls(): bool
    {
        return $this->toolCalls || $this->supports(ModelCapability::Tools);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'reasoning' => $this->reasoning,
            'contextWindow' => $this->contextWindow,
            'maxTokens' => $this->maxTokens,
            'input' => array_values(array_map(
                static fn(ModelCapability $capability): string => $capability->value,
                $this->capabilities,
            )),
            'toolCalls' => $this->supportsToolCalls(),
            'vision' => $this->supportsVision(),
            'thinking' => $this->thinking,
        ];

        if ($this->alias !== null) {
            $data['alias'] = $this->alias;
        }

        if ($this->numCtx !== null) {
            $data['numCtx'] = $this->numCtx;
        }

        if ($this->family !== null && $this->family !== '') {
            $data['family'] = $this->family;
        }

        if ($this->metadataSource !== null && $this->metadataSource !== '') {
            $data['metadataSource'] = $this->metadataSource;
        }

        if ($this->fieldSources !== []) {
            $data['fieldSources'] = $this->fieldSources;
        }

        foreach ($this->extras as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Build from OpenClaw config model entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromOpenClaw(string $provider, array $data): self
    {
        $capabilities = self::normalizeCapabilities($data);
        $vision = self::supportsCapability($capabilities, ModelCapability::Image) || (bool) ($data['vision'] ?? false);
        $toolCalls = self::supportsCapability($capabilities, ModelCapability::Tools) || (bool) ($data['toolCalls'] ?? false);
        $thinking = (bool) ($data['thinking'] ?? false);
        $reasoning = (bool) ($data['reasoning'] ?? self::supportsCapability($capabilities, ModelCapability::Reasoning));

        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? $data['id'] ?? '',
            provider: $provider,
            capabilities: $capabilities,
            reasoning: $reasoning,
            contextWindow: isset($data['contextWindow']) ? (int) $data['contextWindow'] : 4096,
            maxTokens: isset($data['maxTokens']) ? (int) $data['maxTokens'] : 2048,
            alias: $data['alias'] ?? null,
            numCtx: isset($data['numCtx']) ? (int) $data['numCtx'] : null,
            family: isset($data['family']) && is_string($data['family']) ? $data['family'] : null,
            toolCalls: $toolCalls,
            vision: $vision,
            thinking: $thinking,
            metadataSource: isset($data['metadataSource']) && is_string($data['metadataSource']) ? $data['metadataSource'] : 'config',
            fieldSources: self::normalizeFieldSources($data['fieldSources'] ?? []),
            extras: self::collectExtras($data),
        );
    }

    /**
     * Build from a provider discovery payload with best-effort normalization.
     *
     * @param array<string, mixed> $data
     */
    public static function fromDiscovery(string $provider, array $data): ?self
    {
        $id = self::firstString([
            $data['id'] ?? null,
            $data['name'] ?? null,
            $data['model'] ?? null,
        ]);

        if ($id === null || $id === '') {
            return null;
        }

        $name = self::firstString([
            $data['display_name'] ?? null,
            $data['displayName'] ?? null,
            $data['name'] ?? null,
            $id,
        ]) ?? $id;

        $promptTokens = self::firstInt([
            $data['inputTokenLimit'] ?? null,
            $data['max_prompt_tokens'] ?? null,
            $data['maxPromptTokens'] ?? null,
            $data['context_window'] ?? null,
            $data['contextWindow'] ?? null,
            $data['context_length'] ?? null,
            $data['top_provider']['context_length'] ?? null,
            $data['architecture']['context_length'] ?? null,
            $data['capabilities']['limits']['max_prompt_tokens'] ?? null,
        ]);

        $maxTokens = self::firstInt([
            $data['max_output'] ?? null,
            $data['outputTokenLimit'] ?? null,
            $data['max_output_tokens'] ?? null,
            $data['maxOutputTokens'] ?? null,
            $data['max_completion_tokens'] ?? null,
            $data['capabilities']['limits']['max_output_tokens'] ?? null,
        ]);

        $contextWindow = self::firstInt([
            $data['max_context_window_tokens'] ?? null,
            $data['capabilities']['limits']['max_context_window_tokens'] ?? null,
        ]);

        $fieldSources = self::normalizeFieldSources(is_array($data['fieldSources'] ?? null) ? $data['fieldSources'] : []);

        if ($contextWindow !== null && !isset($fieldSources['contextWindow'])) {
            $fieldSources['contextWindow'] = 'provider-api';
        }

        if ($promptTokens !== null && $contextWindow === null && $maxTokens !== null) {
            $contextWindow = $promptTokens + $maxTokens;
            $fieldSources['contextWindow'] = $fieldSources['contextWindow'] ?? 'provider-api';
        } elseif ($promptTokens !== null && $contextWindow === null) {
            $contextWindow = $promptTokens;
            $fieldSources['contextWindow'] = $fieldSources['contextWindow'] ?? 'provider-api';
        }

        if ($maxTokens !== null && !isset($fieldSources['maxTokens'])) {
            $fieldSources['maxTokens'] = 'provider-api';
        }

        if ($maxTokens === null && $promptTokens !== null && $contextWindow !== null && $contextWindow > $promptTokens) {
            $maxTokens = $contextWindow - $promptTokens;
            $fieldSources['maxTokens'] = $fieldSources['maxTokens'] ?? 'provider-api';
        }

        if ($contextWindow === null) {
            $contextWindow = 4096;
            $fieldSources['contextWindow'] = 'heuristic';
        }

        if ($maxTokens === null) {
            $maxTokens = self::heuristicMaxTokens($contextWindow);
            $fieldSources['maxTokens'] = 'heuristic';
        }

        $vision = self::firstBool([
            $data['vision'] ?? null,
            $data['capabilities']['supports']['vision'] ?? null,
            $data['supportsVision'] ?? null,
        ]) ?? self::arrayContains($data['input_modalities'] ?? null, 'image')
            || self::arrayContains($data['architecture']['input_modalities'] ?? null, 'image')
            || self::arrayContains($data['supportedInputModalities'] ?? null, 'image');

        $toolCalls = self::firstBool([
            $data['toolCalls'] ?? null,
            $data['capabilities']['supports']['tool_calls'] ?? null,
            $data['supportsToolCalls'] ?? null,
        ]) ?? self::arrayContains($data['supported_parameters'] ?? null, 'tools');

        $thinking = self::firstBool([
            $data['thinking'] ?? null,
            $data['capabilities']['supports']['thinking'] ?? null,
            $data['supportsThinking'] ?? null,
        ]) ?? false;

        $reasoning = self::firstBool([
            $data['reasoning'] ?? null,
            $data['capabilities']['supports']['reasoning'] ?? null,
            $thinking,
        ]) ?? false;

        $family = self::firstString([
            $data['family'] ?? null,
            $data['details']['family'] ?? null,
            $data['capabilities']['family'] ?? null,
            $data['architecture']['modality'] ?? null,
        ]);

        $capabilities = self::normalizeCapabilities([
            'input' => $vision ? ['text', 'image'] : ['text'],
            'toolCalls' => $toolCalls,
            'reasoning' => $reasoning,
            'thinking' => $thinking,
        ]);

        return new self(
            id: $id,
            name: $name,
            provider: $provider,
            capabilities: $capabilities,
            reasoning: $reasoning,
            contextWindow: $contextWindow,
            maxTokens: $maxTokens,
            alias: self::firstString([$data['alias'] ?? null]),
            numCtx: self::firstInt([$data['numCtx'] ?? null]),
            family: $family,
            toolCalls: $toolCalls,
            vision: $vision,
            thinking: $thinking,
            metadataSource: self::firstString([$data['metadataSource'] ?? null])
                ?? (in_array('heuristic', $fieldSources, true) ? 'heuristic' : 'provider-api'),
            fieldSources: $fieldSources,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return ModelCapability[]
     */
    private static function normalizeCapabilities(array $data): array
    {
        $raw = $data['input'] ?? ['text'];
        $inputs = is_array($raw) ? $raw : ['text'];
        $capabilities = [];

        foreach ($inputs as $input) {
            if (!is_string($input) || $input === '') {
                continue;
            }

            $capabilities[] = ModelCapability::from($input);
        }

        if (($data['reasoning'] ?? false) === true || ($data['thinking'] ?? false) === true) {
            $capabilities[] = ModelCapability::Reasoning;
        }

        if (($data['toolCalls'] ?? false) === true) {
            $capabilities[] = ModelCapability::Tools;
        }

        if (($data['vision'] ?? false) === true) {
            $capabilities[] = ModelCapability::Image;
        }

        if ($capabilities === []) {
            $capabilities[] = ModelCapability::Text;
        }

        return array_values(array_unique($capabilities, SORT_REGULAR));
    }

    /**
     * @param array<string, mixed> $fieldSources
     * @return array<string, string>
     */
    private static function normalizeFieldSources(array $fieldSources): array
    {
        $normalized = [];

        foreach ($fieldSources as $field => $source) {
            if (is_string($field) && $field !== '' && is_string($source) && $source !== '') {
                $normalized[$field] = $source;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function collectExtras(array $data): array
    {
        $known = [
            'id', 'name', 'reasoning', 'contextWindow', 'maxTokens', 'alias', 'numCtx',
            'family', 'toolCalls', 'vision', 'thinking', 'metadataSource', 'fieldSources', 'input',
        ];

        $extras = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $known, true)) {
                $extras[$key] = $value;
            }
        }

        return $extras;
    }

    /**
     * @param ModelCapability[] $capabilities
     */
    private static function supportsCapability(array $capabilities, ModelCapability $capability): bool
    {
        return in_array($capability, $capabilities, true);
    }

    /**
     * @param list<mixed> $values
     */
    private static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $values
     */
    private static function firstInt(array $values): ?int
    {
        foreach ($values as $value) {
            if (is_int($value)) {
                return $value;
            }

            if (is_float($value)) {
                return (int) $value;
            }

            if (is_string($value) && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $values
     */
    private static function firstBool(array $values): ?bool
    {
        foreach ($values as $value) {
            if (is_bool($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function heuristicMaxTokens(int $contextWindow): int
    {
        if ($contextWindow <= 4096) {
            return max(1024, (int) floor($contextWindow / 2));
        }

        return (int) min(16384, max(4096, floor($contextWindow * 0.15)));
    }

    private static function arrayContains(mixed $value, string $needle): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_string($item) && strcasecmp($item, $needle) === 0) {
                return true;
            }
        }

        return false;
    }
}
