<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\CliVendorAdapterInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamProjector;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolArguments;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * Generic provider that drives a CLI binary as an LLM backend.
 *
 * The vendor-specific knowledge (argv shaping, output parsing, model catalog)
 * lives in an injected CliVendorAdapterInterface; the process execution lives
 * in an injected CliRuntimeInterface. This keeps the provider reusable across
 * every CLI vendor (claude, codex, grok, ...) and keeps php-agents free of any
 * concrete subprocess dependency — the host supplies the runtime.
 *
 * It sends no HTTP request, so of $requestLimits only the tool-argument caps
 * (maxToolArgumentBytes, maxToolArgumentDepth) apply: a tool call over either, or whose
 * arguments are not a JSON object, is dropped, in chat() and streamEvents() alike, and a
 * response that would have finished ToolUse finishes Error.
 */
final class CliProvider implements ProviderInterface
{
    public function __construct(
        private string $model,
        private readonly CliVendorAdapterInterface $adapter,
        private readonly CliRuntimeInterface $runtime,
        private readonly RequestLimits $requestLimits = new RequestLimits(),
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $response = $this->adapterChat($messages, $tools, $options);

        $kept = [];
        foreach ($response->toolCalls as $call) {
            $arguments = ToolArguments::fromDecoded($call->arguments, $this->requestLimits);
            if ($arguments !== null) {
                $kept[] = new ToolCall($call->id, $call->name, $arguments, $call->metadata);
            }
        }

        if (count($kept) === count($response->toolCalls)) {
            return $response;
        }

        return new Response(
            content: $response->content,
            finishReason: $response->finishReason === ProviderFinishReason::ToolUse ? ProviderFinishReason::Error : $response->finishReason,
            toolCalls: $kept,
            model: $response->model,
            usage: $response->usage,
            reasoning: $response->reasoning,
            contentBlocks: $response->contentBlocks,
        );
    }

    /**
     * The adapter's parse of one non-streaming run, before the tool-argument caps.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     */
    private function adapterChat(array $messages, array $tools, array $options): Response
    {
        $request = $this->adapter->buildRequest($messages, $tools, $options, $this->model, false);
        $result = $this->runtime->run($request);

        return $this->adapter->parseResult($result, $this->model);
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        return StreamProjector::toResponses($this->streamEvents($messages, $tools, $options));
    }

    /**
     * Each adapter response, in order: reasoning → ReasoningDelta, content → TextDelta, each
     * tool call → ToolInputStart, one ToolInputDelta with the whole JSON arguments,
     * ToolInputEnd (none for a call whose arguments the caps drop; a ToolUse finish then
     * becomes Error). Finish comes last with the last reason other than Stop (ToolUse when tool
     * calls arrived without one, Error when the runtime reported a process error), the last
     * usage, model and content blocks reported. The adapter exposes only mapped reasons, so
     * rawReason is ''. The process stream is pulled one chunk at a time, so an abandoned
     * generator releases it at the next chunk.
     *
     * @return iterable<StreamEvent>
     */
    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $responses = $this->adapterResponses($messages, $tools, $options);

        $reason = ProviderFinishReason::Stop;
        $usage = null;
        $model = $this->model;
        $contentBlocks = [];
        $callCount = 0;
        $badArguments = false;

        foreach ($responses as $response) {
            if ($response->reasoning !== '') {
                yield new ReasoningDelta($response->reasoning);
            }

            if ($response->content !== '') {
                yield new TextDelta($response->content);
            }

            foreach ($response->toolCalls as $call) {
                $index = $callCount++;
                yield new ToolInputStart($index, $call->id, $call->name);
                yield new ToolInputDelta($index, $call->id, ToolArguments::toJson($call->arguments));
                $arguments = ToolArguments::fromDecoded($call->arguments, $this->requestLimits);
                if ($arguments === null) {
                    $badArguments = true;
                    continue;
                }
                yield new ToolInputEnd($index, $call->id, $call->name, $arguments, $call->metadata);
            }

            if ($response->finishReason !== ProviderFinishReason::Stop) {
                $reason = $response->finishReason;
            }

            $usage = $response->usage ?? $usage;
            $model = $response->model !== '' ? $response->model : $model;
            $contentBlocks = $response->contentBlocks !== [] ? $response->contentBlocks : $contentBlocks;
        }

        if ($responses->getReturn()) {
            $reason = ProviderFinishReason::Error;
        } elseif ($reason === ProviderFinishReason::Stop && $callCount > 0) {
            $reason = ProviderFinishReason::ToolUse;
        }

        // A call whose arguments were dropped cannot run: a ToolUse end becomes Error.
        if ($badArguments && $reason === ProviderFinishReason::ToolUse) {
            $reason = ProviderFinishReason::Error;
        }

        yield new Finish($reason, '', $usage, $model, $contentBlocks);
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        // The CLI runs as a raw LLM, so request JSON via a system instruction and
        // best-effort decode the result — no native structured-output protocol.
        $response = $this->chat($messages, [], $options);

        $decoded = json_decode($response->content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/(\{.*\}|\[.*\])/s', $response->content, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $response;
    }

    public function models(): array
    {
        return $this->adapter->discoverModels($this->runtime);
    }

    public function isAvailable(): bool
    {
        return $this->runtime->isAvailable($this->adapter->binaryName());
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /**
     * The adapter's responses for one turn: one per streamed stdout fragment that carries
     * anything, or the single chat() response when the vendor cannot stream. Returns whether
     * the runtime reported a process error.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return \Generator<int, Response, mixed, bool>
     */
    private function adapterResponses(array $messages, array $tools, array $options): \Generator
    {
        if (!$this->adapter->isStreamingSupported()) {
            yield $this->adapterChat($messages, $tools, $options);

            return false;
        }

        $request = $this->adapter->buildRequest($messages, $tools, $options, $this->model, true);

        $carry = [];
        $sawError = false;

        foreach ($this->runtime->stream($request) as $chunk) {
            if ($chunk->error !== null && $chunk->error !== '') {
                $sawError = true;
            }

            $response = $this->adapter->parseChunk($chunk, $this->model, $carry);
            if ($response !== null) {
                yield $response;
            }
        }

        return $sawError;
    }
}
