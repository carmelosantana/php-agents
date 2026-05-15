<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

final class LlamaCppTemplateRegistry
{
    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys(self::definitions());
    }

    public function has(string $template): bool
    {
        return array_key_exists($template, self::definitions());
    }

    public function defaultToolParser(string $template): ?string
    {
        $definition = self::definitions()[$template] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown llama.cpp template: {$template}");
        }

        return $definition['defaultToolParser'];
    }

    /**
     * @return array<string, array{defaultToolParser: ?string}>
     */
    private static function definitions(): array
    {
        return [
            'baseline' => ['defaultToolParser' => 'json'],
            'chatml' => ['defaultToolParser' => 'json'],
            'raw' => ['defaultToolParser' => null],
        ];
    }
}