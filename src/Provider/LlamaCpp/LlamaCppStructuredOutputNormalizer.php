<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Runtime\RuntimeStructuredOutput;

final class LlamaCppStructuredOutputNormalizer
{
    /**
     * @param array<string, mixed> $options
     */
    public function normalize(string $schema, RuntimeModelMetadata $metadata, array $options = []): LlamaCppStructuredOutputContext
    {
        $schemaData = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($schemaData)) {
            throw new \InvalidArgumentException('Structured output schema must decode to an object.');
        }

        $structuredSchema = $schemaData['schema'] ?? $schemaData;
        if (!is_array($structuredSchema)) {
            throw new \InvalidArgumentException('Structured output schema payload must contain an object schema.');
        }

        $normalizedSchema = $this->normalizeSchemaNode($structuredSchema, true);
        $name = is_string($schemaData['name'] ?? null) && $schemaData['name'] !== ''
            ? $schemaData['name']
            : 'structured_output';
        $strict = (bool) ($options['strict'] ?? true);
        $mode = is_string($options['structured_mode'] ?? null) ? $options['structured_mode'] : 'json_schema';

        if ($strict) {
            if (!$this->supportsStructuredMode($metadata, $options, $mode)) {
                throw new \RuntimeException(
                    "Strict structured output mode '{$mode}' is not supported for model {$metadata->id}.",
                );
            }

            return new LlamaCppStructuredOutputContext(
                runtimeStructuredOutput: new RuntimeStructuredOutput(
                    name: $name,
                    schema: $normalizedSchema,
                    strict: true,
                    mode: $mode,
                ),
            );
        }

        if ($this->supportsStructuredMode($metadata, $options, $mode)) {
            return new LlamaCppStructuredOutputContext(
                runtimeStructuredOutput: new RuntimeStructuredOutput(
                    name: $name,
                    schema: $normalizedSchema,
                    strict: false,
                    mode: $mode,
                ),
            );
        }

        if (!in_array($mode, ['json_best_effort', 'best_effort_json'], true)) {
            throw new \RuntimeException(
                "Structured output mode '{$mode}' is not supported for model {$metadata->id}.",
            );
        }

        return new LlamaCppStructuredOutputContext(
            runtimeStructuredOutput: null,
            promptAppendix: "Return valid JSON only matching this schema:\n"
                . json_encode(['name' => $name, 'schema' => $normalizedSchema], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            bestEffort: true,
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeSchemaNode(array $schema, bool $isRoot = false): array
    {
        $type = $schema['type'] ?? null;
        if ($isRoot && $type !== 'object') {
            throw new \InvalidArgumentException('Structured output root schema must declare type "object".');
        }

        if ($type === 'object') {
            $properties = $schema['properties'] ?? [];
            if (!is_array($properties)) {
                throw new \InvalidArgumentException('Structured output object properties must be an object map.');
            }

            $normalizedProperties = [];
            foreach ($properties as $name => $propertySchema) {
                if (!is_array($propertySchema)) {
                    throw new \InvalidArgumentException("Structured output property '{$name}' must be an object schema.");
                }

                $normalizedProperties[$name] = $this->normalizeSchemaNode($propertySchema);
            }

            $required = $schema['required'] ?? [];
            if (!is_array($required)) {
                throw new \InvalidArgumentException('Structured output required must be an array of property names.');
            }

            $schema['properties'] = $normalizedProperties;
            $schema['required'] = array_values(array_map(static fn(mixed $item): string => (string) $item, $required));
            $schema['additionalProperties'] = (bool) ($schema['additionalProperties'] ?? false);

            return $schema;
        }

        if ($type === 'array' && isset($schema['items'])) {
            if (!is_array($schema['items'])) {
                throw new \InvalidArgumentException('Structured output array items must be an object schema.');
            }

            $schema['items'] = $this->normalizeSchemaNode($schema['items']);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function supportsStructuredMode(RuntimeModelMetadata $metadata, array $options, string $mode): bool
    {
        $declaredModes = $options['structuredOutputModes'] ?? $metadata->extras['structuredOutputModes'] ?? null;
        if (is_array($declaredModes)) {
            return in_array($mode, $declaredModes, true);
        }

        $supportsStructuredOutput = $options['supportsStructuredOutput'] ?? $metadata->extras['supportsStructuredOutput'] ?? false;

        return (bool) $supportsStructuredOutput && $mode === 'json_schema';
    }
}