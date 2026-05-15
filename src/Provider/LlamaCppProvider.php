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
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

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

        $stream = $handle->stream($request);

        if ($normalizedTools !== [] && $promptContext->toolParser !== null && $promptContext->toolParser !== 'native') {
            yield from $this->streamWithToolParser(
                $stream,
                $promptContext->toolParser,
                $this->extractToolParserFailurePolicy($options),
            );

            return;
        }

        foreach ($stream as $chunk) {
            yield $this->mapRuntimeChunkToResponse($chunk);
        }
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

    private function mapRuntimeChunkToResponse(RuntimeCompletionChunk $chunk): Response
    {
        return new Response(
            content: $chunk->content,
            finishReason: $this->mapFinishReason($chunk->finishReason),
            toolCalls: $chunk->toolCalls,
            model: $this->model,
            usage: $chunk->usage,
            reasoning: $chunk->reasoning,
        );
    }

    /**
    * @param iterable<RuntimeCompletionChunk> $stream
     * @return iterable<Response>
     */
    private function streamWithToolParser(iterable $stream, string $parserMode, string $failurePolicy): iterable
    {
        $buffer = '';

        foreach ($stream as $chunk) {
            if ($chunk->reasoning !== '') {
                yield new Response(
                    content: '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $this->model,
                    reasoning: $chunk->reasoning,
                );
            }

            if ($chunk->toolCalls !== []) {
                if ($buffer !== '') {
                    yield new Response(
                        content: $buffer,
                        finishReason: ProviderFinishReason::Stop,
                        toolCalls: [],
                        model: $this->model,
                    );
                    $buffer = '';
                }

                yield $this->mapRuntimeChunkToResponse($chunk);
                continue;
            }

            if ($chunk->content !== '') {
                $buffer .= $chunk->content;
            }

            if ($chunk->finishReason === RuntimeFinishReason::ToolUse) {
                if ($buffer === '') {
                    continue;
                }

                try {
                    $toolCalls = $this->toolCallParser->parse($buffer, $parserMode);

                    yield new Response(
                        content: '',
                        finishReason: ProviderFinishReason::ToolUse,
                        toolCalls: $toolCalls,
                        model: $this->model,
                        usage: $chunk->usage,
                    );
                } catch (\Throwable $e) {
                    if ($failurePolicy !== 'content') {
                        throw new \RuntimeException(
                            'Failed to parse llama.cpp tool call output: ' . $e->getMessage(),
                            previous: $e,
                        );
                    }

                    yield new Response(
                        content: $buffer,
                        finishReason: ProviderFinishReason::Stop,
                        toolCalls: [],
                        model: $this->model,
                        usage: $chunk->usage,
                    );
                }

                $buffer = '';
                continue;
            }

            if ($chunk->finishReason !== null) {
                if ($buffer !== '') {
                    yield new Response(
                        content: $buffer,
                        finishReason: $this->mapFinishReason($chunk->finishReason),
                        toolCalls: [],
                        model: $this->model,
                        usage: $chunk->usage,
                    );
                    $buffer = '';
                } else {
                    yield $this->mapRuntimeChunkToResponse($chunk);
                }

                continue;
            }

            if ($chunk->usage !== null) {
                if ($buffer !== '') {
                    yield new Response(
                        content: $buffer,
                        finishReason: ProviderFinishReason::Stop,
                        toolCalls: [],
                        model: $this->model,
                    );
                    $buffer = '';
                }

                yield new Response(
                    content: '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $this->model,
                    usage: $chunk->usage,
                );
            }
        }

        if ($buffer !== '') {
            yield new Response(
                content: $buffer,
                finishReason: ProviderFinishReason::Stop,
                toolCalls: [],
                model: $this->model,
            );
        }
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