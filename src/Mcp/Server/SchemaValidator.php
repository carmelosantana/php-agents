<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Mcp\Server;

final class SchemaValidator
{
    /**
     * Return the first violation, identified by a schema field or list position.
     *
     * @param array<string, mixed> $schema
     */
    public static function validate(array $schema, mixed $value, string $path = ''): ?string
    {
        $field = $path === '' ? 'arguments' : $path;
        if (isset($schema['type']) && !self::matchesType((array) $schema['type'], $value)) {
            return "$field has an invalid type";
        }
        if (isset($schema['enum']) && !array_any(
            $schema['enum'],
            static fn (mixed $allowed): bool => self::sameJsonValue($value, $allowed),
        )) {
            return "$field is not an allowed value";
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return "$field is below the minimum";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return "$field is above the maximum";
            }
        }
        if (is_string($value) && isset($schema['maxLength'])) {
            $length = preg_match_all('/./us', $value);
            if ($length === false || $length > $schema['maxLength']) {
                return "$field has an invalid length";
            }
        }

        $object = self::objectProperties($value);
        if ($object !== null) {
            return self::validateProperties($schema, $object, $path);
        }

        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $error = self::validate($schema['items'], $item, $path . "[$index]");
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /** @return array<array-key, mixed>|null */
    private static function objectProperties(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    private static function sameJsonValue(mixed $value, mixed $allowed): bool
    {
        $object = self::objectProperties($value);
        $allowedObject = self::objectProperties($allowed);
        if (($object === null) !== ($allowedObject === null)) {
            return false;
        }

        $value = $object ?? $value;
        $allowed = $allowedObject ?? $allowed;
        if (!is_array($value) || !is_array($allowed)) {
            if ((is_int($value) || is_float($value)) && (is_int($allowed) || is_float($allowed))) {
                return $value == $allowed;
            }

            return $value === $allowed;
        }

        if (count($value) !== count($allowed)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (!array_key_exists($key, $allowed) || !self::sameJsonValue($item, $allowed[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $object
     */
    private static function validateProperties(array $schema, array $object, string $path): ?string
    {
        $prefix = $path === '' ? '' : $path . '.';
        foreach ($schema['required'] ?? [] as $name) {
            if (!array_key_exists($name, $object)) {
                return $prefix . $name . ' is required';
            }
        }

        foreach ($schema['properties'] ?? [] as $name => $propertySchema) {
            if (array_key_exists($name, $object)) {
                $error = self::validate($propertySchema, $object[$name], $prefix . $name);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $types */
    private static function matchesType(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object' => self::objectProperties($value) !== null,
                'array' => is_array($value) && array_is_list($value),
                'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
