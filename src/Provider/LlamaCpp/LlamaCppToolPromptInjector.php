<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Runtime\RuntimeToolDefinition;

final class LlamaCppToolPromptInjector
{
    /**
     * @param RuntimeToolDefinition[] $tools
     */
    public function inject(array $tools, string $parserMode, string $template): ?string
    {
        if ($tools === [] || $parserMode === 'native') {
            return null;
        }

        if ($parserMode !== 'json') {
            throw new \InvalidArgumentException("Unsupported llama.cpp tool prompt parser mode: {$parserMode}");
        }

        $toolPayload = array_map(static fn(RuntimeToolDefinition $tool): array => [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
        ], $tools);

        $instructions = "Available tools:\n"
            . json_encode($toolPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . "\n\nWhen you need a tool, respond with JSON only using this exact shape:\n"
            . '{"tool_calls":[{"id":"call_<unique_id>","name":"tool_name","arguments":{}}]}'
            . "\nDo not wrap the JSON in markdown and do not add explanatory text.";

        return match ($template) {
            'chatml' => "<|system|>\n{$instructions}",
            default => "SYSTEM: {$instructions}",
        };
    }
}