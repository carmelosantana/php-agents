<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

final readonly class LlamaCppTemplateResolver
{
    public function __construct(
        private LlamaCppTemplateRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function resolveTemplate(RuntimeModelMetadata $metadata, array $options = []): string
    {
        $modelTemplate = $this->stringOption($options, 'modelTemplate');
        if ($modelTemplate !== null) {
            return $this->assertKnownTemplate($modelTemplate, 'model override');
        }

        if ($metadata->defaultTemplate !== null && $metadata->defaultTemplate !== '') {
            return $this->assertKnownTemplate($metadata->defaultTemplate, 'runtime metadata');
        }

        $builtInTemplate = $this->stringOption($options, 'builtInTemplate')
            ?? $this->stringOption($options, 'defaultTemplate');

        if ($builtInTemplate !== null) {
            return $this->assertKnownTemplate($builtInTemplate, 'provider default');
        }

        throw new \RuntimeException(
            'No llama.cpp chat template resolved for model ' . $metadata->id
            . '. Set a per-model template override, expose a runtime default template, or configure a built-in template name.',
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function resolveToolParser(RuntimeModelMetadata $metadata, string $template, array $options = []): ?string
    {
        $modelParser = $this->stringOption($options, 'modelToolParser');
        if ($modelParser !== null) {
            return $this->normalizeParser($modelParser, 'model override');
        }

        if ($metadata->defaultToolParser !== null && $metadata->defaultToolParser !== '') {
            return $this->normalizeParser($metadata->defaultToolParser, 'runtime metadata');
        }

        $templateParser = $this->registry->defaultToolParser($template);
        if ($templateParser !== null) {
            return $this->normalizeParser($templateParser, 'template default');
        }

        $familyParser = $this->familyDefaultToolParser($metadata->family);
        if ($familyParser !== null) {
            return $this->normalizeParser($familyParser, 'family default');
        }

        $providerDefault = $this->stringOption($options, 'defaultToolParser');
        if ($providerDefault !== null) {
            return $this->normalizeParser($providerDefault, 'provider default');
        }

        return null;
    }

    private function assertKnownTemplate(string $template, string $source): string
    {
        if (!$this->registry->has($template)) {
            throw new \RuntimeException(
                "Unsupported llama.cpp template '{$template}' resolved from {$source}. Supported templates: "
                . implode(', ', $this->registry->names()),
            );
        }

        return $template;
    }

    private function familyDefaultToolParser(?string $family): ?string
    {
        if ($family === null || $family === '') {
            return null;
        }

        return match (strtolower($family)) {
            'llama', 'mistral', 'qwen', 'gemma' => 'json',
            default => null,
        };
    }

    private function normalizeParser(string $parser, string $source): string
    {
        return match ($parser) {
            'json', 'native' => $parser,
            default => throw new \RuntimeException("Unsupported llama.cpp tool parser '{$parser}' resolved from {$source}."),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}