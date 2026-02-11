<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Context;

use CarmeloSantana\PHPAgents\Contract\TokenCounterInterface;

final class TokenCounterFactory
{
    public static function forModel(string $providerModel): TokenCounterInterface
    {
        $parts = explode('/', $providerModel, 2);
        $provider = $parts[0] ?? '';
        $model = $parts[1] ?? $providerModel;

        return match ($provider) {
            'openai' => class_exists(\Yethee\Tiktoken\EncoderProvider::class)
                ? new TiktokenCounter(self::selectEncoding($model))
                : new HeuristicCounter(),
            'anthropic' => new HeuristicCounter(3.5),
            default => new HeuristicCounter(),
        };
    }

    private static function selectEncoding(string $model): string
    {
        if (str_contains($model, '4o') || str_contains($model, 'o1') || str_contains($model, 'o3')) {
            return 'o200k_base';
        }

        return 'cl100k_base';
    }
}
