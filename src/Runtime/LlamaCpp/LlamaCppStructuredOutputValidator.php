<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime\LlamaCpp;

final class LlamaCppStructuredOutputValidator
{
    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function decodeAndValidate(string $json, array $schema): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('Structured output must decode to an object.');
        }

        $this->validateNode($decoded, $schema, '$');

        return $decoded;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateNode(mixed $value, array $schema, string $path): void
    {
        if (array_key_exists('enum', $schema)) {
            $enum = $schema['enum'];
            if (!is_array($enum) || !in_array($value, $enum, true)) {
                throw new \RuntimeException("Structured output value at {$path} is not in the allowed enum.");
            }
        }

        $type = $schema['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return;
        }

        match ($type) {
            'object' => $this->validateObject($value, $schema, $path),
            'array' => $this->validateArray($value, $schema, $path),
            'string' => $this->assertType(is_string($value), $path, 'string'),
            'integer' => $this->assertType(is_int($value), $path, 'integer'),
            'number' => $this->assertType(is_int($value) || is_float($value), $path, 'number'),
            'boolean' => $this->assertType(is_bool($value), $path, 'boolean'),
            'null' => $this->assertType($value === null, $path, 'null'),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateObject(mixed $value, array $schema, string $path): void
    {
        $this->assertType(is_array($value) && !array_is_list($value), $path, 'object');
        if (!is_array($value)) {
            return;
        }

        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        $additionalProperties = (bool) ($schema['additionalProperties'] ?? false);

        if (!is_array($properties)) {
            throw new \RuntimeException("Structured output schema at {$path} has invalid properties.");
        }

        foreach ($required as $property) {
            if (!array_key_exists((string) $property, $value)) {
                throw new \RuntimeException("Structured output is missing required property {$path}.{$property}.");
            }
        }

        foreach ($value as $property => $propertyValue) {
            if (!array_key_exists($property, $properties)) {
                if (!$additionalProperties) {
                    throw new \RuntimeException("Structured output contains unsupported property {$path}.{$property}.");
                }

                continue;
            }

            $propertySchema = $properties[$property];
            if (!is_array($propertySchema)) {
                throw new \RuntimeException("Structured output schema for {$path}.{$property} is invalid.");
            }

            $this->validateNode($propertyValue, $propertySchema, $path . '.' . $property);
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateArray(mixed $value, array $schema, string $path): void
    {
        $this->assertType(is_array($value) && array_is_list($value), $path, 'array');
        if (!is_array($value)) {
            return;
        }

        $items = $schema['items'] ?? null;
        if (!is_array($items)) {
            return;
        }

        foreach ($value as $index => $item) {
            $this->validateNode($item, $items, $path . '[' . $index . ']');
        }
    }

    private function assertType(bool $condition, string $path, string $expected): void
    {
        if (!$condition) {
            throw new \RuntimeException("Structured output value at {$path} must be {$expected}.");
        }
    }
}