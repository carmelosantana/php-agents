<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Internal;

/**
 * `x-mcp-header` (2026-07-28 streamable-http §Custom Headers from Tool Parameters).
 *
 * A property in a tool's inputSchema may carry `"x-mcp-header": "Region"`; a client
 * then MUST send `Mcp-Param-Region: <value>` on `tools/call`, and MUST drop from its
 * tool list any tool with an invalid annotation. extract() returns null for such a
 * tool. An annotation is invalid if it is:
 * - not a non-empty RFC 9110 token (the pattern carries the `D` modifier: without it
 *   PCRE's `$` matches before a final newline, and "Region\n" would pass into the
 *   header name, which the spec forbids by name);
 * - a case-insensitive duplicate of another;
 * - on a property whose `type` is not exactly one of string, integer or boolean
 *   (a `null` alongside is allowed; an absent `type` is not);
 * - anywhere not reachable purely through nested `properties`.
 *
 * A nested property is reachable. Spec §2 point 5 opens with "top-level" but rules out
 * only what is "not reachable through `properties`"; upstream settles it, allowing a
 * nested object's property as long as every step of the chain is a `properties` key.
 *
 * That last check is a comparison rather than a walk of the other keywords: count()
 * counts the annotations it can see anywhere in the schema, walk() collects the ones it
 * reached through `properties`, and an annotation under `items`, under a combinator or
 * a conditional, in `$defs` or behind a `$ref` lifts the first number without lifting
 * the second, so the tool is dropped.
 *
 * Which `x-mcp-header` keys count() reads as annotations depends on the position it is
 * in. In a schema, `x-mcp-header` is an annotation; `default`, `const`, `enum` and
 * `examples` hold instance data rather than subschemas and are not entered, so a key of
 * that name inside a default value or an example is not one; the value of `properties`
 * is entered as a map of names; and any other value is entered as a schema, which is
 * what leaves an unreachable annotation counted. In a name map no key is a keyword, so
 * a property may be called `x-mcp-header` or `default` without either reading as one,
 * and each value is read as a schema again.
 *
 * The two sides therefore agree along any chain of `properties`. A map keyed by names
 * under some other keyword is still read as a schema, and can still differ: a tool
 * whose `$defs` holds a definition named `x-mcp-header` is dropped although no
 * annotation is involved. That behaviour is older than the name-map distinction, it
 * fails closed, and it is left recorded for Task 12 rather than patched here.
 *
 * headers() converts values the way §Value Encoding does: strings through HeaderValue,
 * integers as decimals, booleans as `true`/`false`. A null or absent value sends no
 * header, which the spec requires. A float carrying no fractional part converts as an
 * integer, printed in full rather than cast — `json_decode` reads `{"shard": 12.0}` as
 * a PHP float, the spec has servers compare a header with the body numerically, and
 * `(string) 1.0e18` would say `1.0E+18`. Anything else — a fractional float, INF, NAN,
 * an array — sends nothing. The spec does not let the annotation sit on a property that
 * accepts one of those (it names `number` as not permitted), so such an argument is
 * already off its schema, and omitting the header leaves the server to reject the call.
 *
 * @internal
 */
final class ParamHeaders
{
    private const TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    private const PRIMITIVES = ['string', 'integer', 'boolean'];

    /** JSON Schema keywords whose values are instance data, not subschemas. */
    private const INSTANCE_DATA = ['default', 'const', 'enum', 'examples'];

    /**
     * @param array<array-key, mixed> $inputSchema
     * @return list<array{path: list<string>, header: string}>|null
     */
    public static function extract(array $inputSchema): ?array
    {
        $found = [];
        $seen = [];
        if (!self::walk($inputSchema, [], $found, $seen)) {
            return null;
        }

        $reached = count($found);

        return self::count($inputSchema) === $reached ? $found : null;
    }

    /**
     * @param list<array{path: list<string>, header: string}> $map
     * @param array<array-key, mixed> $arguments
     * @return array<string, string>
     */
    public static function headers(array $map, array $arguments): array
    {
        $headers = [];
        foreach ($map as $entry) {
            $value = $arguments;
            foreach ($entry['path'] as $segment) {
                $value = is_array($value) && array_key_exists($segment, $value) ? $value[$segment] : null;
            }
            $encoded = match (true) {
                is_string($value) => HeaderValue::encode($value),
                is_int($value) => (string) $value,
                is_bool($value) => $value ? 'true' : 'false',
                is_float($value) && is_finite($value) && floor($value) === $value => sprintf('%.0F', $value),
                default => null,
            };
            if ($encoded !== null) {
                $headers['Mcp-Param-' . $entry['header']] = $encoded;
            }
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string> $path
     * @param list<array{path: list<string>, header: string}> $found
     * @param array<string, true> $seen
     */
    private static function walk(array $schema, array $path, array &$found, array &$seen): bool
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return true;
        }
        foreach ($properties as $name => $property) {
            if (!is_array($property)) {
                continue;
            }
            $here = [...$path, (string) $name];
            if (array_key_exists('x-mcp-header', $property)) {
                $header = $property['x-mcp-header'];
                if (!is_string($header) || preg_match(self::TOKEN, $header) !== 1 || !self::primitive($property['type'] ?? null)) {
                    return false;
                }
                $key = strtolower($header);
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;
                $found[] = ['path' => $here, 'header' => $header];
            }
            if (!self::walk($property, $here, $found, $seen)) {
                return false;
            }
        }

        return true;
    }

    private static function primitive(mixed $type): bool
    {
        if (is_string($type)) {
            return in_array($type, self::PRIMITIVES, true);
        }
        if (!is_array($type)) {
            return false;
        }
        $types = array_values(array_filter($type, static fn(mixed $t): bool => $t !== 'null'));

        return count($types) === 1 && in_array($types[0], self::PRIMITIVES, true);
    }

    private static function count(mixed $node): int
    {
        if (!is_array($node)) {
            return 0;
        }
        $count = array_key_exists('x-mcp-header', $node) ? 1 : 0;
        foreach ($node as $key => $child) {
            if ($key === 'x-mcp-header' || in_array($key, self::INSTANCE_DATA, true)) {
                continue;
            }
            $count += $key === 'properties' ? self::countNames($child) : self::count($child);
        }

        return $count;
    }

    /**
     * The same count over a `properties` map, whose keys are property names rather than
     * keywords: nothing here is read as `x-mcp-header` or as instance data, and every
     * value is a schema again.
     */
    private static function countNames(mixed $node): int
    {
        if (!is_array($node)) {
            return 0;
        }
        $count = 0;
        foreach ($node as $child) {
            $count += self::count($child);
        }

        return $count;
    }
}
