<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

/**
 * Shared JSON Schema normalization utilities for provider-specific formatting.
 *
 * Each method operates on a single schema node (no recursion). Providers
 * compose these in their own recursive traversal, since per-node
 * transformations vary by provider.
 */
final class SchemaUtils
{
    /**
     * Strip specified keywords from a schema node.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $keywords
     * @return array<string, mixed>
     */
    public static function stripKeywords(array $schema, array $keywords): array
    {
        foreach ($keywords as $keyword) {
            unset($schema[$keyword]);
        }

        return $schema;
    }

    /**
     * Flatten a union combinator (anyOf/oneOf/allOf) into a single type.
     *
     * Picks the first non-null variant and merges its fields into the
     * parent schema. Falls back to `string` if all variants are null.
     *
     * @param array<string, mixed> $schema Must contain the given $combinator key.
     * @return array<string, mixed>
     */
    public static function flattenCombinator(array $schema, string $combinator): array
    {
        /** @var list<mixed> $variants */
        $variants = $schema[$combinator];
        unset($schema[$combinator]);

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (($variant['type'] ?? null) === 'null') {
                continue;
            }

            return [...$schema, ...$variant];
        }

        // All variants were null — fall back to string
        $schema['type'] = 'string';

        return $schema;
    }

    /**
     * Demote validation constraints into the description field.
     *
     * Appends human-readable hints for each matched keyword so the LLM
     * still respects constraints after the keywords are stripped.
     *
     * @param array<string, mixed> $schema
     * @param array<string, string> $templates Keyword → sprintf template map.
     * @return array<string, mixed>
     */
    public static function demoteConstraints(array $schema, array $templates): array
    {
        $hints = [];

        foreach ($templates as $keyword => $template) {
            if (!isset($schema[$keyword])) {
                continue;
            }

            $value = $schema[$keyword];
            $display = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');
            $hints[] = sprintf($template, $display);
        }

        if ($hints !== []) {
            $existing = $schema['description'] ?? '';
            $suffix = implode(' ', $hints);
            $schema['description'] = $existing !== ''
                ? $existing . ' ' . $suffix
                : $suffix;
        }

        return $schema;
    }
}
