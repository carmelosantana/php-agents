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
 * Throughout, an object node is whatever normalize() would rewrite as an object:
 * declared `type: object` (possibly nullable via a `type` array), or carrying
 * `properties` with no `type` at all — the shape a server-supplied MCP schema
 * leaves behind below its root: SchemaTool::toFunctionSchema() defaults only the
 * ROOT `type`, and JsonSchemaRepair defaults none. Every check
 * here shares that one definition, in {@see isObjectNode()}, including normalize()'s
 * own rewrite gate.
 *
 * A schema qualifies unless some node in it is:
 * - an open object: `additionalProperties` present and not `false`;
 * - a free-form object: no `properties` (or an empty map) and `additionalProperties`
 *   not `false`, which JSON Schema reads as "any keys"; closing it would silently
 *   allow no arguments at all;
 * - a `$ref`, or has `patternProperties`, whose targets normalize() cannot close;
 * - a node whose non-empty `properties` is a stdClass (a map with numeric-string
 *   keys, as JsonSchemaRepair leaves it), which normalize() cannot walk;
 * - a node carrying `properties` that is not an object node, i.e. one with an
 *   explicit non-object `type`: normalize()'s gate skips it, so nothing under its
 *   `properties` would ever be closed. `type: ["object", "null"]` is an object
 *   node and is unaffected;
 * - an object anywhere under a position normalize() does not rewrite — every
 *   single-subschema keyword ({@see SINGLE_SCHEMA_KEYWORDS}) and every schema map
 *   in {@see UNREWRITTEN_SCHEMA_MAP_KEYWORDS}: leaving one in would send an
 *   unclosed object with `strict: true`, which OpenAI rejects outright. The rule
 *   is uniform, so even an object already closed and fully required there makes
 *   the schema not qualify.
 *
 * Unlike {@see SchemaUtils} (per-node helpers), these methods recurse over the
 * tree, but not over the same positions. `qualifies()` walks every position that
 * can hold a subschema in the JSON Schema 2020-12 core, applicator, unevaluated
 * and content vocabularies, plus the older spellings `definitions`,
 * `additionalItems` and draft-07 `dependencies`. It does not follow a `$ref`; a
 * `$ref` disqualifies the schema outright instead.
 *
 * `normalize()` rewrites a strictly smaller set: properties, items, prefixItems,
 * `$defs`/`definitions` and the combinator branches. Every other position above
 * is left as written, which is why an object there disqualifies the schema rather
 * than being normalized. Two of those positions disqualify for a stronger reason
 * than the object they hold: `patternProperties` disqualifies outright, and
 * `additionalProperties` disqualifies as soon as it is present and not `false`,
 * object or not.
 *
 * Those two sets are restated in more than one place here, so the invariant that
 * matters is pinned by a test rather than by this comment: "every subschema
 * position is either disqualified or fully closed by normalize" enumerates the
 * keywords from the specification and fails if any position is neither rewritten
 * nor judged.
 */
final class StrictSchemaNormalizer
{
    /**
     * Keywords whose value is a single subschema. normalize() rewrites none of
     * them, so an object under any of them disqualifies the schema.
     */
    private const SINGLE_SCHEMA_KEYWORDS = [
        'additionalProperties', 'additionalItems', 'contains', 'propertyNames',
        'unevaluatedProperties', 'unevaluatedItems', 'contentSchema', 'not', 'if',
        'then', 'else',
    ];

    /**
     * Keywords whose value is a map of subschemas. normalize() rewrites `properties`,
     * `$defs` and `definitions`; `patternProperties` disqualifies a schema outright;
     * the rest are in {@see UNREWRITTEN_SCHEMA_MAP_KEYWORDS}.
     */
    private const SCHEMA_MAP_KEYWORDS = [
        'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'dependencies',
    ];

    /**
     * Schema-map keywords normalize() does not rewrite, so an object under one
     * disqualifies the schema the same way a `contains` object does. Draft-07
     * `dependencies` may hold a list of property names instead of a subschema;
     * such a list simply contains no object and does not disqualify anything.
     */
    private const UNREWRITTEN_SCHEMA_MAP_KEYWORDS = ['dependentSchemas', 'dependencies'];

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
        if (self::isObjectNode($schema) && array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
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
     * so the schema stays satisfiable. A node with `properties` and no `type` counts
     * as an object here, as {@see isObjectNode()} defines it. A non-object node is
     * returned with the subschemas under `items`, `prefixItems`, `$defs`/`definitions`
     * and the combinators normalized.
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
            $schema['items'] = self::isTupleItems($schema['items'])
                ? array_map(static fn(mixed $node): mixed => is_array($node) ? self::normalize($node) : $node, $schema['items'])
                : self::normalize($schema['items']);
        }

        if (!self::isObjectNode($schema)) {
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

        // An object in a position normalize() never rewrites can only go out as
        // written — unclosed, under strict:true, which the API refuses. Disqualify
        // uniformly rather than prove each such subtree already strict-clean.
        foreach (self::SINGLE_SCHEMA_KEYWORDS as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword]) && self::containsObject($schema[$keyword])) {
                return true;
            }
        }

        foreach (self::UNREWRITTEN_SCHEMA_MAP_KEYWORDS as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            foreach ($schema[$keyword] as $node) {
                if (is_array($node) && self::containsObject($node)) {
                    return true;
                }
            }
        }

        // children() walks `properties` on any node, but normalize() only rewrites it
        // behind the object-node gate. A node with an explicit non-object `type` that
        // still carries `properties` therefore falls between the two: walked, never
        // rewritten, never judged. Disqualify — closing a `type: string` node would
        // be meaningless, so refuse to vouch for it.
        if (isset($schema['properties']) && !self::isObjectNode($schema)) {
            return true;
        }

        if (self::isObjectNode($schema) && ($schema['additionalProperties'] ?? null) !== false) {
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
     * Whether this node or any node beneath it is an object.
     *
     * @param array<array-key, mixed> $schema
     */
    private static function containsObject(array $schema): bool
    {
        if (self::isObjectNode($schema)) {
            return true;
        }

        foreach (self::children($schema) as $child) {
            if (self::containsObject($child)) {
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
     * Whether an `items` value is the tuple form (a list of per-position subschemas)
     * rather than a single subschema applied to every element. The empty array is
     * ambiguous and read as a single subschema.
     *
     * @param array<array-key, mixed> $items
     */
    private static function isTupleItems(array $items): bool
    {
        return array_is_list($items) && $items !== [];
    }

    /**
     * An object node as normalize() rewrites one: declared `type: object` (possibly
     * nullable), or carrying `properties` with no `type` at all.
     *
     * @param array<array-key, mixed> $schema
     */
    private static function isObjectNode(array $schema): bool
    {
        return self::isObject($schema) || (!isset($schema['type']) && isset($schema['properties']));
    }

    /**
     * The subschemas directly under this node, for every keyword this class knows:
     * {@see SCHEMA_MAP_KEYWORDS}, {@see SINGLE_SCHEMA_KEYWORDS}, the combinators,
     * `prefixItems` and `items`. A keyword absent from those is not walked at all —
     * add it there and to the invariant test's keyword list together.
     *
     * @param array<array-key, mixed> $schema
     * @return list<array<array-key, mixed>>
     */
    private static function children(array $schema): array
    {
        $children = [];
        foreach (self::SCHEMA_MAP_KEYWORDS as $keyword) {
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
            if (self::isTupleItems($schema['items'])) {
                foreach ($schema['items'] as $node) {
                    if (is_array($node)) {
                        $children[] = $node;
                    }
                }
            } else {
                $children[] = $schema['items'];
            }
        }
        foreach (self::SINGLE_SCHEMA_KEYWORDS as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $children[] = $schema[$keyword];
            }
        }

        return $children;
    }
}
