<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Cli;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\CliVendorAdapterInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * Adapter for the Claude Code CLI (`claude`) run as a headless raw LLM.
 *
 * The binary is invoked in print mode (`-p`) with all built-in tools disabled
 * (`--tools ""`) and MCP/session/hook discovery suppressed, so it behaves like a
 * plain chat completion. The full conversation (including any prior tool history)
 * is flattened into a single prompt on stdin; Coqui's own toolkits and safety
 * model stay in control of the turn.
 *
 * Auth is delegated entirely to the user's existing `claude` install (API key or
 * `claude setup-token`). We never drive a claude.ai subscription login, which
 * Anthropic's terms reserve for approved integrations.
 */
final class ClaudeCliVendorAdapter implements CliVendorAdapterInterface
{
    private const DEFAULT_CONTEXT_WINDOW = 200000;

    public function __construct(
        private readonly string $binary = 'claude',
    ) {}

    public function binaryName(): string
    {
        return $this->binary;
    }

    public function buildRequest(
        array $messages,
        array $tools,
        array $options,
        string $model,
        bool $stream,
    ): CliProcessRequest {
        [$systemPrompt, $prompt] = $this->flattenConversation($messages);

        $arguments = [
            '-p',
            '--tools', '',
            '--strict-mcp-config',
            '--bare',
            '--no-session-persistence',
        ];

        if ($model !== '') {
            $arguments[] = '--model';
            $arguments[] = $model;
        }

        if ($systemPrompt !== '') {
            $arguments[] = '--system-prompt';
            $arguments[] = $systemPrompt;
        }

        if ($stream) {
            $arguments[] = '--output-format';
            $arguments[] = 'stream-json';
            $arguments[] = '--verbose';
            $arguments[] = '--include-partial-messages';
        } else {
            $arguments[] = '--output-format';
            $arguments[] = 'json';
        }

        $timeout = isset($options['timeout']) && is_numeric($options['timeout'])
            ? (float) $options['timeout']
            : null;

        return new CliProcessRequest(
            binary: $this->binary,
            arguments: $arguments,
            stdin: $prompt,
            timeout: $timeout,
            model: $model,
        );
    }

    public function parseResult(CliProcessResult $result, string $model): Response
    {
        $data = json_decode(trim($result->stdout), true);

        // The CLI may exit non-zero yet still emit a structured result (e.g.
        // "Not logged in"). Prefer the JSON's own error message when present.
        if (is_array($data) && ($data['is_error'] ?? false) === true) {
            $message = is_string($data['result'] ?? null) && $data['result'] !== ''
                ? $data['result']
                : 'claude CLI reported an error.';

            throw new \RuntimeException("claude CLI error: {$message}");
        }

        if (!$result->isSuccess() && !is_array($data)) {
            throw new \RuntimeException(sprintf(
                'claude CLI exited with code %d: %s',
                $result->exitCode,
                trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout),
            ));
        }

        if (!is_array($data)) {
            throw new \RuntimeException('claude CLI did not return valid JSON output.');
        }

        $content = is_string($data['result'] ?? null) ? $data['result'] : '';
        $resolvedModel = is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $model;

        return new Response(
            content: $content,
            finishReason: $this->mapStopReason($data['stop_reason'] ?? null),
            model: $resolvedModel,
            usage: $this->extractUsage(is_array($data['usage'] ?? null) ? $data['usage'] : null),
        );
    }

    public function parseChunk(CliProcessChunk $chunk, string $model, array &$carry): ?Response
    {
        // The CLI emits NDJSON: accumulate raw bytes and emit a Response per
        // complete line so partial lines that span chunk boundaries are handled.
        $buffer = (string) ($carry['buffer'] ?? '');
        $buffer .= $chunk->content;

        $responses = [];

        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);

            $response = $this->parseEventLine($line, $model, $carry);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        // Flush a trailing line with no newline on the final chunk.
        if ($chunk->isLast && trim($buffer) !== '') {
            $response = $this->parseEventLine($buffer, $model, $carry);
            if ($response !== null) {
                $responses[] = $response;
            }
            $buffer = '';
        }

        $carry['buffer'] = $buffer;

        if ($responses === []) {
            return null;
        }

        // parseChunk returns at most one Response per call; merge text and keep
        // the last finish reason/usage seen across the lines in this fragment.
        return $this->mergeResponses($responses, $model);
    }

    public function isStreamingSupported(): bool
    {
        return true;
    }

    public function discoverModels(CliRuntimeInterface $runtime): array
    {
        // The `claude` CLI has NO machine-readable model-list command — it only
        // accepts `--model <alias|id>`. There is therefore no way to query the
        // live model catalog from the binary; this curated list is the source of
        // truth and is intentionally limited to the stable aliases the CLI
        // resolves at request time. Any full id (e.g. `claude-opus-4-8`) also
        // works via the model string and Coqui's openclaw.json catalog.
        $curated = [
            ['id' => 'fable', 'name' => 'Claude Fable (CLI)', 'maxTokens' => 64000],
            ['id' => 'opus', 'name' => 'Claude Opus (CLI)', 'maxTokens' => 32000],
            ['id' => 'sonnet', 'name' => 'Claude Sonnet (CLI)', 'maxTokens' => 16000],
            ['id' => 'haiku', 'name' => 'Claude Haiku (CLI)', 'maxTokens' => 8192],
        ];

        return array_map(
            fn(array $entry): ModelDefinition => new ModelDefinition(
                id: $entry['id'],
                name: $entry['name'],
                provider: 'claude-cli',
                capabilities: [ModelCapability::Text, ModelCapability::Reasoning],
                reasoning: true,
                contextWindow: self::DEFAULT_CONTEXT_WINDOW,
                maxTokens: $entry['maxTokens'],
                family: 'claude',
                thinking: true,
                metadataSource: 'static-fallback',
                fieldSources: [
                    'contextWindow' => 'static-fallback',
                    'maxTokens' => 'static-fallback',
                ],
            ),
            $curated,
        );
    }

    /**
     * Split messages into a system prompt and a flattened transcript prompt.
     *
     * @param MessageInterface[] $messages
     * @return array{0: string, 1: string}
     */
    private function flattenConversation(array $messages): array
    {
        $systemPrompt = '';
        $lines = [];

        foreach ($messages as $message) {
            if ($message->role() === Role::System) {
                $systemPrompt = $this->stringifyContent($message->content());
                continue;
            }

            $lines[] = $this->renderMessage($message);
        }

        return [$systemPrompt, implode("\n\n", array_filter($lines, static fn(string $l): bool => $l !== ''))];
    }

    private function renderMessage(MessageInterface $message): string
    {
        $text = $this->stringifyContent($message->content());

        return match ($message->role()) {
            Role::Assistant => $this->renderAssistant($message, $text),
            Role::Tool => $this->renderToolResult($message, $text),
            default => $text !== '' ? "User: {$text}" : '',
        };
    }

    private function renderAssistant(MessageInterface $message, string $text): string
    {
        $parts = [];
        if ($text !== '') {
            $parts[] = "Assistant: {$text}";
        }

        foreach ($message->toolCalls() as $toolCall) {
            $args = json_encode($toolCall->arguments, JSON_UNESCAPED_SLASHES);
            $parts[] = sprintf('Assistant called tool %s with %s', $toolCall->name, $args !== false ? $args : '{}');
        }

        return implode("\n", $parts);
    }

    private function renderToolResult(MessageInterface $message, string $text): string
    {
        $id = $message->toolCallId();
        $label = $id !== null && $id !== '' ? " (call {$id})" : '';

        return sprintf('Tool result%s: %s', $label, $text);
    }

    /**
     * @param string|array<array<string, mixed>> $content
     */
    private function stringifyContent(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $parts = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text']) && is_string($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Parse one NDJSON line from `--output-format stream-json`.
     *
     * The CLI emits (verified against the real binary): `system`/init metadata,
     * zero or more `stream_event` envelopes carrying Anthropic SSE deltas (only
     * when `--include-partial-messages` is set), a complete `assistant` message,
     * and a terminal `result` carrying the full text + usage + stop reason.
     *
     * To avoid double-counting we prefer incremental `stream_event` text deltas
     * when they appear and suppress the duplicate text from the later `assistant`
     * and `result` events; if no deltas arrived we fall back to the `assistant`
     * (or `result`) full text so content is never silently dropped.
     *
     * @param array<string, mixed> $carry
     */
    private function parseEventLine(string $line, string $model, array &$carry): ?Response
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $event = json_decode($line, true);
        if (!is_array($event)) {
            return null;
        }

        return match ($event['type'] ?? '') {
            'stream_event' => $this->parseStreamEvent($event, $model, $carry),
            'assistant' => $this->parseAssistantEvent($event, $model, $carry),
            'result' => $this->parseResultEvent($event, $model, $carry),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $carry
     */
    private function parseStreamEvent(array $event, string $model, array &$carry): ?Response
    {
        $delta = $event['event']['delta'] ?? null;
        if (!is_array($delta) || ($delta['type'] ?? '') !== 'text_delta' || !is_string($delta['text'] ?? null)) {
            return null;
        }

        // Mark that incremental deltas are flowing so the terminal full-text
        // events know not to re-emit the same content.
        $carry['saw_delta'] = true;

        return new Response(
            content: $delta['text'],
            finishReason: ProviderFinishReason::Stop,
            model: $model,
        );
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $carry
     */
    private function parseAssistantEvent(array $event, string $model, array &$carry): ?Response
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $resolvedModel = is_string($message['model'] ?? null) && $message['model'] !== '' && $message['model'] !== '<synthetic>'
            ? $message['model']
            : $model;

        // When partial deltas already streamed the text, don't repeat it — but
        // still surface usage if the assistant message carries it.
        $content = ($carry['saw_delta'] ?? false)
            ? ''
            : $this->stringifyContent(is_array($message['content'] ?? null) ? $message['content'] : []);

        $usage = $this->extractUsage(is_array($message['usage'] ?? null) ? $message['usage'] : null);

        if ($content !== '') {
            $carry['saw_assistant_text'] = true;
        }

        if ($content === '' && $usage === null) {
            return null;
        }

        return new Response(
            content: $content,
            finishReason: ProviderFinishReason::Stop,
            model: $resolvedModel,
            usage: $usage,
        );
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $carry
     */
    private function parseResultEvent(array $event, string $model, array &$carry): Response
    {
        if (($event['is_error'] ?? false) === true) {
            $message = is_string($event['result'] ?? null) && $event['result'] !== ''
                ? $event['result']
                : 'claude CLI reported an error.';

            throw new \RuntimeException("claude CLI error: {$message}");
        }

        // The result event restates the full text; only use it as a fallback when
        // neither streamed deltas nor the assistant event produced content.
        $content = ($carry['saw_delta'] ?? false) || ($carry['saw_assistant_text'] ?? false)
            ? ''
            : (is_string($event['result'] ?? null) ? $event['result'] : '');

        return new Response(
            content: $content,
            finishReason: $this->mapStopReason($event['stop_reason'] ?? null),
            model: is_string($event['model'] ?? null) && $event['model'] !== '' ? $event['model'] : $model,
            usage: $this->extractUsage(is_array($event['usage'] ?? null) ? $event['usage'] : null),
        );
    }

    /**
     * @param Response[] $responses
     */
    private function mergeResponses(array $responses, string $model): Response
    {
        $content = '';
        $finishReason = ProviderFinishReason::Stop;
        $usage = null;
        $resolvedModel = $model;

        foreach ($responses as $response) {
            $content .= $response->content;
            $finishReason = $response->finishReason;
            $usage = $response->usage ?? $usage;
            $resolvedModel = $response->model !== '' ? $response->model : $resolvedModel;
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            model: $resolvedModel,
            usage: $usage,
        );
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    private function extractUsage(?array $usage): ?Usage
    {
        if ($usage === null) {
            return null;
        }

        $input = $this->intValue($usage['input_tokens'] ?? null)
            + $this->intValue($usage['cache_read_input_tokens'] ?? null)
            + $this->intValue($usage['cache_creation_input_tokens'] ?? null);
        $output = $this->intValue($usage['output_tokens'] ?? null);

        if ($input === 0 && $output === 0) {
            return null;
        }

        return new Usage(
            promptTokens: $input,
            completionTokens: $output,
            totalTokens: $input + $output,
        );
    }

    private function intValue(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function mapStopReason(mixed $stopReason): ProviderFinishReason
    {
        return match ($stopReason) {
            'max_tokens' => ProviderFinishReason::MaxTokens,
            'tool_use' => ProviderFinishReason::ToolUse,
            'error', 'error_during_execution' => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
    }
}
