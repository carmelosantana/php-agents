<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

/**
 * Normalizes a JSON Schema tree for OpenAI strict Structured Outputs, shared by
 * the OpenAI providers (Chat Completions + Responses).
 *
 * OpenAI's strict mode rejects any object schema that is not fully closed and
 * fully required. `qualifies()` reports whether a schema can satisfy strict mode
 * at all (an intentional open map cannot); `normalize()` rewrites a qualifying
 * schema so it does.
 *
 * Unlike {@see SchemaUtils} (per-node helpers), both methods recurse over the
 * whole schema tree.
 */
final class StrictSchemaNormalizer
{
    /**
     * Whether the schema (or any nested node) is an open object — an object whose
     * `additionalProperties` is present and not `false`. Such a schema cannot
     * satisfy strict mode, so strict must be disabled for it.
     *
     * @param array<string, mixed> $schema
     */
    public static function containsOpenObject(array $schema): bool
    {
        if (($schema['type'] ?? null) === 'object' && array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            return true;
        }

        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $property) {
                if (is_array($property) && self::containsOpenObject($property)) {
                    return true;
                }
            }
        }

        $items = $schema['items'] ?? null;
        if (is_array($items) && self::containsOpenObject($items)) {
            return true;
        }

        $additionalProperties = $schema['additionalProperties'] ?? null;
        if (is_array($additionalProperties) && self::containsOpenObject($additionalProperties)) {
            return true;
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $combinator) {
            $variants = $schema[$combinator] ?? null;
            if (!is_array($variants)) {
                continue;
            }

            foreach ($variants as $variant) {
                if (is_array($variant) && self::containsOpenObject($variant)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rewrite a JSON Schema object for OpenAI strict mode.
     *
     * Strict mode rules:
     * - `additionalProperties` must be `false`.
     * - `required` must list every key in `properties`.
     * - Properties the caller left optional are typed nullable via
     *   `anyOf: [{...original}, {type: "null"}]` so the schema stays satisfiable.
     *
     * Applied recursively to nested object schemas.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        $schema['additionalProperties'] = false;

        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            $schema['required'] = $schema['required'] ?? [];

            return $schema;
        }

        $allKeys = array_keys($schema['properties']);
        $required = isset($schema['required']) && is_array($schema['required'])
            ? $schema['required']
            : [];
        $optionalKeys = array_diff($allKeys, $required);

        // Wrap optional properties as nullable so the schema stays valid.
        foreach ($optionalKeys as $key) {
            $prop = $schema['properties'][$key];
            if (is_array($prop) && !isset($prop['anyOf'])) {
                $schema['properties'][$key] = ['anyOf' => [$prop, ['type' => 'null']]];
            }
        }

        // Recurse into nested object properties.
        foreach ($schema['properties'] as $key => $prop) {
            if (is_array($prop) && ($prop['type'] ?? '') === 'object') {
                $schema['properties'][$key] = self::normalize($prop);
            }
        }

        $schema['required'] = $allKeys;

        return $schema;
    }
}
