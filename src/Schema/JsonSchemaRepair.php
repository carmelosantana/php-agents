<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Schema;

/**
 * Restores the JSON objects that `json_decode($json, true)` flattened into PHP arrays.
 *
 * A decoded `{}` and a decoded `[]` are the same PHP value, and json_encode() writes
 * both back as `[]`. A schema from outside (an MCP server's `inputSchema`, an ability's
 * input schema) is decoded that way, and providers reject `"properties": []`. repair()
 * turns an array back into an object only at the keywords whose value must be an
 * object. Those are the JSON Schema 2020-12 keywords plus the older spellings still
 * met in the wild — `definitions`, superseded by `$defs`, and `additionalItems`,
 * removed in 2020-12:
 *
 * - map-valued (`properties`, `patternProperties`, `$defs`, `definitions`,
 *   `dependentSchemas`): always an object, including a non-empty map keyed "0",
 *   "1", … in order, which json_decode() makes a list;
 * - schema-valued (`additionalProperties`, `unevaluatedProperties`, `items`,
 *   `additionalItems`, `unevaluatedItems`, `contains`, `not`, `if`, `then`, `else`,
 *   `propertyNames`, `contentSchema`): an empty array becomes `{}`, and anything else
 *   is recursed into. The exception is `items` as a non-empty list, the draft-04 tuple
 *   form, which is a list of schemas.
 *
 * Schema lists (`anyOf`, `oneOf`, `allOf`, `prefixItems`) are recursed into and stay
 * lists. Value keywords (`enum`, `const`, `default`, `examples`, `required`, `type`)
 * are never touched: their `[]` may really be an empty list. Draft-07 `dependencies`
 * is left alone for that same reason — its values mix schemas with string lists, so
 * which `[]` was an object cannot be told from the decoded value.
 *
 * repair() fires only on a keyword it finds, which makes the root a precondition on
 * the caller rather than something this class can fix. A schema with no keywords at
 * all — the `{}` an MCP tool that takes no input publishes — has nothing to fire on
 * and is returned, and re-encoded, as `[]`; the signature returns an array, so a root
 * `\stdClass` is not a value repair() can produce. A caller that may be handed such a
 * schema must establish a root `type` before calling, the way
 * SchemaTool::toFunctionSchema() does with `$schema['type'] ??= 'object'`.
 */
final class JsonSchemaRepair
{
    private const MAP_KEYWORDS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    private const SCHEMA_KEYWORDS = [
        'additionalProperties', 'unevaluatedProperties', 'items', 'additionalItems', 'unevaluatedItems',
        'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'contentSchema',
    ];

    private const LIST_KEYWORDS = ['anyOf', 'oneOf', 'allOf', 'prefixItems'];

    /**
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    public static function repair(array $schema): array
    {
        foreach (self::MAP_KEYWORDS as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            $map = $schema[$keyword];
            foreach ($map as $name => $subschema) {
                if (is_array($subschema)) {
                    $map[$name] = self::subschema($subschema);
                }
            }
            $schema[$keyword] = array_is_list($map) ? (object) $map : $map;
        }

        foreach (self::SCHEMA_KEYWORDS as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            $value = $schema[$keyword];
            if ($keyword === 'items' && $value !== [] && array_is_list($value)) {
                $schema[$keyword] = array_map(
                    static fn(mixed $item): mixed => is_array($item) ? self::subschema($item) : $item,
                    $value,
                );
                continue;
            }
            $schema[$keyword] = self::subschema($value);
        }

        foreach (self::LIST_KEYWORDS as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            foreach ($schema[$keyword] as $index => $subschema) {
                if (is_array($subschema)) {
                    $schema[$keyword][$index] = self::subschema($subschema);
                }
            }
        }

        return $schema;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>|\stdClass
     */
    private static function subschema(array $schema): array|\stdClass
    {
        return $schema === [] ? new \stdClass() : self::repair($schema);
    }
}
