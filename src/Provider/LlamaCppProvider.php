<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\LocalModelHandleInterface;
use CarmeloSantana\PHPAgents\Contract\LocalModelRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppHistoryFormatter;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppMultimodalNormalizer;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppProjectorResolver;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppTemplateRegistry;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppTemplateResolver;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppStructuredOutputNormalizer;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolCallParser;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolPromptInjector;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolSchemaNormalizer;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamProjector;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolArguments;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * @deprecated 0.18 Use \CarmeloSantana\PHPAgents\Provider\LlamaServerProvider (llama.cpp's llama-server).
 *             The FFI binding matches llama.h only up to llama.cpp b9174 (newer builds segfault)
 *             and, with tools (always under Agent), buffers the whole generation. Removed or
 *             fixed in 0.20.
 */
final class LlamaCppProvider implements ProviderInterface
{
    private const INTERNAL_REQUEST_OPTION_KEYS = [
        'sequence_id',
        'sequenceId',
        'tool_parser_failure_policy',
        'toolParserFailurePolicy',
        'modelTemplate',
        'builtInTemplate',
        'defaultTemplate',
        'modelToolParser',
        'defaultToolParser',
    ];

    private ?LocalModelHandleInterface $handle = null;

    private readonly LlamaCppHistoryFormatter $historyFormatter;

    private readonly LlamaCppToolSchemaNormalizer $toolSchemaNormalizer;

    private readonly LlamaCppToolCallParser $toolCallParser;

    private readonly LlamaCppMultimodalNormalizer $multimodalNormalizer;

    private readonly LlamaCppStructuredOutputNormalizer $structuredOutputNormalizer;

    /**
     * @param array<string, mixed> $runtimeOptions
     */
    public function __construct(
        private string $model,
        private readonly LocalModelRuntimeInterface $runtime,
        private readonly array $runtimeOptions = [],
        ?LlamaCppHistoryFormatter $historyFormatter = null,
        ?LlamaCppToolSchemaNormalizer $toolSchemaNormalizer = null,
        ?LlamaCppToolCallParser $toolCallParser = null,
        ?LlamaCppMultimodalNormalizer $multimodalNormalizer = null,
        ?LlamaCppStructuredOutputNormalizer $structuredOutputNormalizer = null,
    ) {
        $registry = new LlamaCppTemplateRegistry();
        $this->historyFormatter = $historyFormatter ?? new LlamaCppHistoryFormatter(
            new LlamaCppTemplateResolver($registry),
            new LlamaCppToolPromptInjector(),
        );
        $this->toolSchemaNormalizer = $toolSchemaNormalizer ?? new LlamaCppToolSchemaNormalizer();
        $this->toolCallParser = $toolCallParser ?? new LlamaCppToolCallParser();
        $this->multimodalNormalizer = $multimodalNormalizer ?? new LlamaCppMultimodalNormalizer(new LlamaCppProjectorResolver());
        $this->structuredOutputNormalizer = $structuredOutputNormalizer ?? new LlamaCppStructuredOutputNormalizer();
    }

    public function __destruct()
    {
        $this->handle?->close();
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        return $this->aggregateResponses($this->stream($messages, $tools, $options));
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        return StreamProjector::toResponses($this->streamEvents($messages, $tools, $options));
    }

    /**
     * Each runtime chunk, in order:
     *   reasoning   → ReasoningDelta
     *   content     → TextDelta; on the tool-parser path it is buffered until the finish
     *                 chunk, then parsed into tool calls (ToolUse) or emitted as one TextDelta
     *                 (a chunk carrying runtime tool calls flushes the buffer as text)
     *   toolCalls   → ToolInputStart, one ToolInputDelta with the whole JSON arguments,
     *                 ToolInputEnd
     * Finish comes last with the last finish reason and usage the runtime reported (no finish
     * reason is Stop, as in RuntimeCompletionResult); a Stop answer that carried tool calls is
     * ToolUse, and parser output kept as text under the `content` failure policy is Stop.
     * The runtime stream is pulled one chunk at a time, so an abandoned generator stops it at
     * the next chunk.
     *
     * @return iterable<StreamEvent>
     */
    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $handle = $this->handle();
        $metadata = $handle->model();
        $normalizedTools = $this->toolSchemaNormalizer->normalize($tools);
        $promptContext = $this->historyFormatter->format($messages, $metadata, $normalizedTools, $this->runtimeOptions);
        $multimodalContext = $this->multimodalNormalizer->normalize($messages, $metadata, $this->runtimeOptions);

        $request = new RuntimeCompletionRequest(
            prompt: $promptContext->prompt,
            images: $multimodalContext->images,
            tools: $normalizedTools,
            sequenceId: $this->extractSequenceId($options),
            options: $this->buildRequestOptions(
                $options,
                $promptContext->template,
                $promptContext->toolParser,
                [],
                $multimodalContext->requestOptions,
            ),
        );

        $parserMode = $normalizedTools !== [] && $promptContext->toolParser !== null && $promptContext->toolParser !== 'native'
            ? $promptContext->toolParser
            : null;
        $failurePolicy = $this->extractToolParserFailurePolicy($options);

        $buffer = '';
        $callCount = 0;
        $usage = null;
        $finishReason = null;
        $keptAsText = false;

        foreach ($handle->stream($request) as $chunk) {
            $usage = $chunk->usage ?? $usage;

            if ($chunk->reasoning !== '') {
                yield new ReasoningDelta($chunk->reasoning);
            }

            if ($parserMode === null || $chunk->toolCalls !== []) {
                $text = $buffer . $chunk->content;
                $buffer = '';
                if ($text !== '') {
                    yield new TextDelta($text);
                }
            } else {
                $buffer .= $chunk->content;
            }

            foreach ($chunk->toolCalls as $call) {
                yield from $this->toolCallEvents($callCount++, $call);
            }

            if ($chunk->finishReason === null) {
                continue;
            }

            $finishReason = $chunk->finishReason;

            if ($buffer === '') {
                continue;
            }

            if ($finishReason !== RuntimeFinishReason::ToolUse || $parserMode === null) {
                yield new TextDelta($buffer);
                $buffer = '';

                continue;
            }

            try {
                $parsed = $this->toolCallParser->parse($buffer, $parserMode);
            } catch (\Throwable $e) {
                if ($failurePolicy !== 'content') {
                    throw new \RuntimeException(
                        'Failed to parse llama.cpp tool call output: ' . $e->getMessage(),
                        previous: $e,
                    );
                }

                yield new TextDelta($buffer);
                $buffer = '';
                $keptAsText = true;

                continue;
            }

            $buffer = '';
            foreach ($parsed as $call) {
                yield from $this->toolCallEvents($callCount++, $call);
            }
        }

        if ($buffer !== '') {
            yield new TextDelta($buffer);
        }

        $reason = $this->mapFinishReason($finishReason);
        if ($keptAsText) {
            $reason = ProviderFinishReason::Stop;
        } elseif ($reason === ProviderFinishReason::Stop && $callCount > 0) {
            $reason = ProviderFinishReason::ToolUse;
        }

        yield new Finish($reason, $finishReason->value ?? '', $usage, $this->model);
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $handle = $this->handle();
        $metadata = $handle->model();
        $promptContext = $this->historyFormatter->format($messages, $metadata, [], $this->runtimeOptions);
        $multimodalContext = $this->multimodalNormalizer->normalize($messages, $metadata, $this->runtimeOptions);
        $structuredContext = $this->structuredOutputNormalizer->normalize($schema, $metadata, [...$this->runtimeOptions, ...$options]);
        $prompt = $promptContext->prompt;
        if ($structuredContext->promptAppendix !== null && $structuredContext->promptAppendix !== '') {
            $prompt .= "\n\n" . $structuredContext->promptAppendix;
        }

        $result = $handle->generate(new RuntimeCompletionRequest(
            prompt: $prompt,
            images: $multimodalContext->images,
            tools: [],
            sequenceId: $this->extractSequenceId($options),
            structuredOutput: $structuredContext->runtimeStructuredOutput,
            options: $this->buildRequestOptions(
                $options,
                $promptContext->template,
                null,
                ['strict', 'structured_mode'],
                $multimodalContext->requestOptions,
            ),
        ));

        return $this->decodeStructuredResult($result, $structuredContext->bestEffort);
    }

    public function models(): array
    {
        return array_map($this->toModelDefinition(...), $this->runtime->models());
    }

    public function isAvailable(): bool
    {
        return $this->runtime->isAvailable();
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;
        $clone->handle = null;

        return $clone;
    }

    private function handle(): LocalModelHandleInterface
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $this->handle = $this->runtime->open($this->model, $this->runtimeOptions);

        return $this->handle;
    }

    /**
     * @param iterable<Response> $responses
     */
    private function aggregateResponses(iterable $responses): Response
    {
        $content = '';
        $reasoning = '';
        $toolCalls = [];
        $model = $this->model;
        $usage = null;
        $finishReason = ProviderFinishReason::Stop;

        foreach ($responses as $response) {
            $content .= $response->content;
            $reasoning .= $response->reasoning;
            $toolCalls = [...$toolCalls, ...$response->toolCalls];
            $model = $response->model !== '' ? $response->model : $model;
            $usage = $response->usage ?? $usage;
            $finishReason = $response->finishReason;
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $model,
            usage: $usage,
            reasoning: $reasoning,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param string[] $additionalExcludedKeys
     * @param array<string, mixed> $extraOptions
     * @return array<string, mixed>
     */
    private function buildRequestOptions(
        array $options,
        string $template,
        ?string $toolParser,
        array $additionalExcludedKeys = [],
        array $extraOptions = [],
    ): array {
        $merged = [...$this->runtimeOptions, ...$options, ...$extraOptions];

        foreach ([...self::INTERNAL_REQUEST_OPTION_KEYS, ...$additionalExcludedKeys] as $excludedKey) {
            unset($merged[$excludedKey]);
        }

        $merged['template'] = $template;

        if ($toolParser !== null) {
            $merged['toolParser'] = $toolParser;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractSequenceId(array $options): ?string
    {
        $sequenceId = $options['sequenceId'] ?? $options['sequence_id'] ?? null;

        return is_string($sequenceId) && $sequenceId !== '' ? $sequenceId : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractToolParserFailurePolicy(array $options): string
    {
        $policy = $options['tool_parser_failure_policy']
            ?? $options['toolParserFailurePolicy']
            ?? $this->runtimeOptions['toolParserFailurePolicy']
            ?? $this->runtimeOptions['tool_parser_failure_policy']
            ?? 'error';

        return $policy === 'content' ? 'content' : 'error';
    }

    private function decodeStructuredResult(RuntimeCompletionResult $result, bool $bestEffort = false): mixed
    {
        if ($result->content === '') {
            throw new \RuntimeException('Structured output response was empty.');
        }

        if (!$bestEffort) {
            return json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        }

        $decoded = json_decode($result->content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/(\{.*\}|\[.*\])/s', $result->content, $matches) === 1) {
            return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        }

        throw new \RuntimeException('Best-effort structured output did not contain valid JSON.');
    }

    private function mapFinishReason(?RuntimeFinishReason $finishReason): ProviderFinishReason
    {
        return match ($finishReason) {
            RuntimeFinishReason::ToolUse => ProviderFinishReason::ToolUse,
            RuntimeFinishReason::MaxTokens => ProviderFinishReason::MaxTokens,
            RuntimeFinishReason::Error, RuntimeFinishReason::Cancelled => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
    }

    /**
     * @return \Generator<int, StreamEvent>
     */
    private function toolCallEvents(int $index, ToolCall $call): \Generator
    {
        yield new ToolInputStart($index, $call->id, $call->name);
        yield new ToolInputDelta($index, $call->id, ToolArguments::toJson($call->arguments));
        yield new ToolInputEnd($index, $call->id, $call->name, $call->arguments, $call->metadata);
    }

    private function toModelDefinition(RuntimeModelMetadata $metadata): ModelDefinition
    {
        $capabilities = [ModelCapability::Text];

        if ($metadata->supportsVision) {
            $capabilities[] = ModelCapability::Image;
        }

        if ($metadata->supportsTools) {
            $capabilities[] = ModelCapability::Tools;
        }

        if ($metadata->supportsReasoning) {
            $capabilities[] = ModelCapability::Reasoning;
        }

        return new ModelDefinition(
            id: $metadata->id,
            name: $metadata->name,
            provider: 'llama-cpp',
            capabilities: $capabilities,
            reasoning: $metadata->supportsReasoning,
            contextWindow: $metadata->contextWindow,
            maxTokens: $metadata->maxTokens,
            alias: $metadata->aliases[0] ?? null,
            numCtx: isset($metadata->extras['numCtx']) ? (int) $metadata->extras['numCtx'] : null,
            family: $metadata->family,
            toolCalls: $metadata->supportsTools,
            vision: $metadata->supportsVision,
            thinking: $metadata->supportsThinking,
            metadataSource: 'runtime',
            fieldSources: [
                'contextWindow' => 'runtime',
                'maxTokens' => 'runtime',
            ],
            extras: array_filter([
                'path' => $metadata->path,
                'projectorPath' => $metadata->projectorPath,
                'defaultTemplate' => $metadata->defaultTemplate,
                'defaultToolParser' => $metadata->defaultToolParser,
                ...$metadata->extras,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        );
    }
}