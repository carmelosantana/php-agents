<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Schema;

/**
 * Restores the JSON objects that `json_decode($json, true)` flattened into PHP arrays.
 *
 * A decoded `{}` and a decoded `[]` are the same PHP value, and json_encode() writes
 * both back as `[]`. A schema from outside (an MCP server's `inputSchema`, an ability's
 * input schema) is decoded that way, and providers reject `"properties": []`. repair()
 * turns an array back into an object only at the JSON Schema 2020-12 keywords whose
 * value must be an object:
 *
 * - map-valued (`properties`, `patternProperties`, `$defs`, `definitions`,
 *   `dependentSchemas`): always an object, including a non-empty map whose keys
 *   are numeric strings, which PHP stores as a list;
 * - schema-valued (`additionalProperties`, `unevaluatedProperties`, `items`,
 *   `additionalItems`, `unevaluatedItems`, `contains`, `not`, `if`, `then`, `else`,
 *   `propertyNames`): an empty array becomes `{}`, and anything else is recursed
 *   into. The exception is `items` as a non-empty list, the draft-04 tuple form,
 *   which is a list of schemas.
 *
 * Schema lists (`anyOf`, `oneOf`, `allOf`, `prefixItems`) are recursed into and stay
 * lists. Value keywords (`enum`, `const`, `default`, `examples`, `required`, `type`)
 * are never touched: their `[]` may really be an empty list.
 */
final class JsonSchemaRepair
{
    private const MAP_KEYWORDS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    private const SCHEMA_KEYWORDS = [
        'additionalProperties', 'unevaluatedProperties', 'items', 'additionalItems', 'unevaluatedItems',
        'contains', 'not', 'if', 'then', 'else', 'propertyNames',
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
