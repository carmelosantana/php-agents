<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

/**
 * Normalizes a JSON Schema tree for OpenAI strict Structured Outputs, shared by
 * the OpenAI providers (Chat Completions `structured()` and Responses tools).
 *
 * OpenAI's strict mode rejects any object schema that is not fully closed and
 * fully required. `qualifies()` reports whether normalize() can make a schema
 * satisfy strict mode without changing what it accepts; `normalize()` rewrites
 * a qualifying schema so it does.
 *
 * A schema qualifies unless some node in it is:
 * - an open object: `additionalProperties` present and not `false`;
 * - a free-form object: no `properties` (or an empty map) and `additionalProperties`
 *   not `false`, which JSON Schema reads as "any keys"; closing it would silently
 *   allow no arguments at all;
 * - a `$ref`, or has `patternProperties`, whose targets normalize() cannot close;
 * - a node whose non-empty `properties` is a stdClass (a map with numeric-string
 *   keys, as JsonSchemaRepair leaves it), which normalize() cannot walk.
 *
 * Unlike {@see SchemaUtils} (per-node helpers), these methods recurse over the
 * tree, but not over the same positions. `qualifies()` inspects every subschema
 * position: properties, patternProperties, `$defs`/`definitions`, items (schema
 * or tuple), prefixItems, additionalProperties, `anyOf`/`oneOf`/`allOf`, `not`
 * and `if`/`then`/`else`. `normalize()` rewrites only the positions it closes:
 * properties, items, prefixItems, `$defs`/`definitions` and the combinator
 * branches; objects under `not` or `if`/`then`/`else` are left as written.
 */
final class StrictSchemaNormalizer
{
    /**
     * @param array<array-key, mixed> $schema
     */
    public static function qualifies(array $schema): bool
    {
        return !self::containsOpenObject($schema) && !self::containsUnclosable($schema);
    }

    /**
     * Whether the schema (or any nested node) is an open object: an object whose
     * `additionalProperties` is present and not `false`.
     *
     * @param array<array-key, mixed> $schema
     */
    public static function containsOpenObject(array $schema): bool
    {
        if (self::isObject($schema) && array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            return true;
        }

        foreach (self::children($schema) as $child) {
            if (self::containsOpenObject($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite a JSON Schema for OpenAI strict mode.
     *
     * An object node gets `additionalProperties: false` and a `required` that
     * lists every key in `properties`. A property the caller left optional is
     * normalized first and then typed nullable via `anyOf: [{...}, {type: "null"}]`,
     * so the schema stays satisfiable. A non-object node is returned with the
     * subschemas under `items`, `prefixItems`, `$defs`/`definitions` and the
     * combinators normalized.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    public static function normalize(array $schema): array
    {
        foreach (['anyOf', 'oneOf', 'allOf', 'prefixItems'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $schema[$keyword] = array_map(
                    static fn(mixed $node): mixed => is_array($node) ? self::normalize($node) : $node,
                    $schema[$keyword],
                );
            }
        }

        foreach (['$defs', 'definitions'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                foreach ($schema[$keyword] as $name => $node) {
                    if (is_array($node)) {
                        $schema[$keyword][$name] = self::normalize($node);
                    }
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = array_is_list($schema['items']) && $schema['items'] !== []
                ? array_map(static fn(mixed $node): mixed => is_array($node) ? self::normalize($node) : $node, $schema['items'])
                : self::normalize($schema['items']);
        }

        if (!self::isObject($schema) && !(!isset($schema['type']) && isset($schema['properties']))) {
            return $schema;
        }

        $schema['additionalProperties'] = false;

        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            $schema['required'] = $schema['required'] ?? [];

            return $schema;
        }

        $allKeys = array_keys($schema['properties']);
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];

        foreach ($schema['properties'] as $key => $property) {
            if (!is_array($property)) {
                continue;
            }
            $property = self::normalize($property);
            if (!in_array($key, $required, true) && !isset($property['anyOf'])) {
                $property = ['anyOf' => [$property, ['type' => 'null']]];
            }
            $schema['properties'][$key] = $property;
        }

        $schema['required'] = $allKeys;

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private static function containsUnclosable(array $schema): bool
    {
        if (isset($schema['$ref']) || isset($schema['patternProperties'])) {
            return true;
        }

        // A non-empty properties map that JsonSchemaRepair had to cast to an object
        // (numeric-string keys) can't be walked or listed in `required` as strings.
        if (($schema['properties'] ?? null) instanceof \stdClass && get_object_vars($schema['properties']) !== []) {
            return true;
        }

        if (self::isObject($schema) && ($schema['additionalProperties'] ?? null) !== false) {
            $properties = $schema['properties'] ?? null;
            $empty = $properties === null || $properties === [] || ($properties instanceof \stdClass && get_object_vars($properties) === []);
            if ($empty) {
                return true;
            }
        }

        foreach (self::children($schema) as $child) {
            if (self::containsUnclosable($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private static function isObject(array $schema): bool
    {
        $type = $schema['type'] ?? null;

        return $type === 'object' || (is_array($type) && in_array('object', $type, true));
    }

    /**
     * Every subschema directly under this node.
     *
     * @param array<array-key, mixed> $schema
     * @return list<array<array-key, mixed>>
     */
    private static function children(array $schema): array
    {
        $children = [];
        foreach (['properties', 'patternProperties', '$defs', 'definitions'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                foreach ($schema[$keyword] as $node) {
                    if (is_array($node)) {
                        $children[] = $node;
                    }
                }
            }
        }
        foreach (['anyOf', 'oneOf', 'allOf', 'prefixItems'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                foreach ($schema[$keyword] as $node) {
                    if (is_array($node)) {
                        $children[] = $node;
                    }
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items']) && $schema['items'] !== []) {
                foreach ($schema['items'] as $node) {
                    if (is_array($node)) {
                        $children[] = $node;
                    }
                }
            } else {
                $children[] = $schema['items'];
            }
        }
        foreach (['additionalProperties', 'not', 'if', 'then', 'else'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $children[] = $schema[$keyword];
            }
        }

        return $children;
    }
}
