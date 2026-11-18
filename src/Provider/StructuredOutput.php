<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;

/**
 * One call shape for structured output across providers.
 *
 * ProviderInterface::structured() takes the schema as a JSON string and returns mixed: some
 * providers return the decoded array, others a Response whose content is the JSON text.
 * extract() accepts an array schema and always returns an array.
 */
final class StructuredOutput
{
    /**
     * @param MessageInterface[] $messages
     * @param array<string, mixed>|string $schema JSON Schema as an array or a JSON string
     * @param array<string, mixed> $options
     * @return array<mixed> The decoded result, or [] when the model gave no JSON object or array
     */
    public static function extract(ProviderInterface $provider, array $messages, array|string $schema, array $options = []): array
    {
        $json = is_string($schema) ? $schema : json_encode($schema, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return self::decode($provider->structured($messages, $json, $options));
    }

    /**
     * Normalise whatever ProviderInterface::structured() returned into an array.
     *
     * @return array<mixed>
     */
    public static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!$raw instanceof Response) {
            return [];
        }

        $text = trim($raw->content);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $text, $m) === 1) {
            $text = $m[1];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
