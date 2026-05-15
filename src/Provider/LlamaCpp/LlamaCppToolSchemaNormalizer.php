<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Provider\SchemaUtils;
use CarmeloSantana\PHPAgents\Runtime\RuntimeToolDefinition;

final class LlamaCppToolSchemaNormalizer
{
    private const UNSUPPORTED_SCHEMA_KEYWORDS = [
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
        'oneOf',
        'anyOf',
        'allOf',
        'const',
        '$ref',
        '$defs',
        'patternProperties',
        'default',
    ];

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
     * @param ToolInterface[] $tools
     * @return RuntimeToolDefinition[]
     */
    public function normalize(array $tools): array
    {
        return array_map(function (ToolInterface $tool): RuntimeToolDefinition {
            $schema = $tool->toFunctionSchema();
            $function = is_array($schema['function'] ?? null) ? $schema['function'] : [];
            $parameters = $function['parameters'] ?? [];

            return new RuntimeToolDefinition(
                name: is_string($function['name'] ?? null) ? $function['name'] : $tool->name(),
                description: is_string($function['description'] ?? null) ? $function['description'] : $tool->description(),
                parameters: is_array($parameters)
                    ? $this->sanitizeSchema($parameters)
                    : ['type' => 'object', 'properties' => [], 'additionalProperties' => false, 'required' => []],
            );
        }, $tools);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function sanitizeSchema(array $schema): array
    {
        foreach (['anyOf', 'oneOf', 'allOf'] as $combinator) {
            if (isset($schema[$combinator]) && is_array($schema[$combinator])) {
                $schema = SchemaUtils::flattenCombinator($schema, $combinator);
            }
        }

        $schema = SchemaUtils::demoteConstraints($schema, self::DEMOTABLE_KEYWORDS);
        $schema = SchemaUtils::stripKeywords($schema, self::UNSUPPORTED_SCHEMA_KEYWORDS);

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = $this->sanitizeSchema($property);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->sanitizeSchema($schema['items']);
        }

        if (!isset($schema['required']) || !is_array($schema['required'])) {
            $schema['required'] = [];
        }

        return $schema;
    }
}