<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\LlamaCpp;

use CarmeloSantana\PHPAgents\Tool\ToolCall;

final class LlamaCppToolCallParser
{
    /**
     * @return ToolCall[]
     */
    public function parse(string $content, string $parserMode): array
    {
        return match ($parserMode) {
            'json' => $this->parseJson($content),
            default => throw new \InvalidArgumentException("Unsupported llama.cpp tool parser mode: {$parserMode}"),
        };
    }

    /**
     * @return ToolCall[]
     */
    private function parseJson(string $content): array
    {
        $json = trim($this->stripCodeFence($content));
        if ($json === '') {
            throw new \RuntimeException('Tool call payload was empty.');
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tool call payload must decode to an object.');
        }

        $toolCalls = $decoded['tool_calls'] ?? $decoded['toolCalls'] ?? $decoded['calls'] ?? null;
        if (!is_array($toolCalls)) {
            if (isset($decoded['name']) || isset($decoded['function'])) {
                $toolCalls = [$decoded];
            } else {
                throw new \RuntimeException('Tool call payload did not contain a tool_calls array.');
            }
        }

        $result = [];

        foreach ($toolCalls as $index => $toolCall) {
            if (!is_array($toolCall)) {
                throw new \RuntimeException('Each tool call payload entry must be an object.');
            }

            $name = $toolCall['name'] ?? $toolCall['function']['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException('Tool call payload did not contain a valid tool name.');
            }

            $arguments = $toolCall['arguments'] ?? $toolCall['input'] ?? $toolCall['function']['arguments'] ?? [];
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }

            if (!is_array($arguments)) {
                throw new \RuntimeException("Tool call '{$name}' did not contain an object of arguments.");
            }

            $id = $toolCall['id'] ?? $toolCall['tool_call_id'] ?? null;
            if (!is_string($id) || $id === '') {
                $id = 'call_' . substr(hash('sha256', $name . json_encode($arguments, JSON_THROW_ON_ERROR) . ':' . $index), 0, 12);
            }

            $result[] = new ToolCall($id, $name, $arguments);
        }

        return $result;
    }

    private function stripCodeFence(string $content): string
    {
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', trim($content), $matches) !== 1) {
            return $content;
        }

        return $matches[1];
    }
}