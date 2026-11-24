<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\CliVendorAdapterInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Contract\UsageReporting;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessRequest;
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
use Psr\Log\LoggerInterface;

/**
 * Generic provider that drives a CLI binary as an LLM backend.
 *
 * The vendor-specific knowledge (argv shaping, output parsing, model catalog)
 * lives in an injected CliVendorAdapterInterface; the process execution lives
 * in an injected CliRuntimeInterface. This keeps the provider reusable across
 * every CLI vendor (claude, codex, grok, ...) and keeps php-agents free of any
 * concrete subprocess dependency — the host supplies the runtime.
 *
 * It sends no HTTP request, so of $requestLimits only these apply:
 *   - maxResponseBytes caps the process's stdout: streamEvents() stops reading the process
 *     stream once its fragments pass it, and chat() refuses a larger result, each with a
 *     ProviderException ("response exceeded N bytes"); 0 means no cap. A runtime's run()
 *     buffers the whole output before chat() can check it, so a host that needs a hard bound
 *     on a non-streaming vendor enforces it in the runtime too.
 *   - maxToolArgumentBytes and maxToolArgumentDepth: a tool call over either, or whose
 *     arguments are not a JSON object, is dropped, in chat() and streamEvents() alike, and a
 *     response that would have finished ToolUse finishes Error.
 */
final class CliProvider implements ProviderInterface, UsageReporting
{
    private ?UsageListener $usageListener = null;

    public function __construct(
        private string $model,
        private readonly CliVendorAdapterInterface $adapter,
        private readonly CliRuntimeInterface $runtime,
        private RequestLimits $requestLimits = new RequestLimits(),
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function withUsageListener(UsageListener $listener): static
    {
        $clone = clone $this;
        $clone->usageListener = $listener;

        return $clone;
    }

    /**
     * Return a copy whose later calls use $limits; of them only maxResponseBytes and the
     * tool-argument caps apply.
     * The original keeps its limits.
     */
    public function withRequestLimits(RequestLimits $limits): static
    {
        $clone = clone $this;
        $clone->requestLimits = $limits;

        return $clone;
    }

    public function requestLimits(): RequestLimits
    {
        return $this->requestLimits;
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $operation = $this->usageOperation($options, 'chat');
        $request = $this->adapter->buildRequest($messages, $tools, $options, $this->model, false);

        $response = null;
        $reason = ProviderFinishReason::Error;
        $processFailed = false;
        $start = (int) hrtime(true);
        try {
            $response = $this->adapterChat($request, $processFailed);
            $response = $this->limitToolCalls($response);
            $reason = $response->finishReason;

            return $response;
        } finally {
            $this->reportUsage($operation, $start, $response?->model, $response?->usage, $reason, $processFailed);
        }
    }

    private function limitToolCalls(Response $response): Response
    {
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
            // As in streamEvents(): a Stop that carried tool calls is a tool-use stop, and a
            // tool-use stop that drops a call is an Error.
            finishReason: in_array($response->finishReason, [ProviderFinishReason::ToolUse, ProviderFinishReason::Stop], true)
                ? ProviderFinishReason::Error
                : $response->finishReason,
            toolCalls: $kept,
            model: $response->model,
            usage: $response->usage,
            reasoning: $response->reasoning,
            contentBlocks: $response->contentBlocks,
        );
    }

    /** The adapter's parse of one non-streaming run, before the tool-argument caps. */
    private function adapterChat(CliProcessRequest $request, bool &$processFailed): Response
    {
        $result = $this->runtime->run($request);
        $processFailed = !$result->isSuccess();
        $this->checkOutputBytes(strlen($result->stdout));

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
        $operation = $this->usageOperation($options, 'stream');
        $stream = $this->adapter->isStreamingSupported();
        $request = $this->adapter->buildRequest($messages, $tools, $options, $this->model, $stream);

        $reason = ProviderFinishReason::Stop;
        $usage = null;
        $model = $this->model;
        $contentBlocks = [];
        $callCount = 0;
        $badArguments = false;
        $finish = null;
        $processFailed = false;

        $start = (int) hrtime(true);
        try {
            $responses = $this->adapterResponses($request, $stream);
            foreach ($responses as $response) {
                $usage = $response->usage ?? $usage;
                $model = $response->model !== '' ? $response->model : $model;
                $contentBlocks = $response->contentBlocks !== [] ? $response->contentBlocks : $contentBlocks;

                if ($response->reasoning !== '') {
                    yield new ReasoningDelta($response->reasoning);
                }

                if ($response->content !== '') {
                    yield new TextDelta($response->content);
                }

                foreach ($response->toolCalls as $call) {
                    $index = $callCount++;
                    yield new ToolInputStart($index, $call->id, $call->name);
                    // Checked before the delta, so arguments over a cap are never yielded.
                    $arguments = ToolArguments::fromDecoded($call->arguments, $this->requestLimits);
                    if ($arguments === null) {
                        $badArguments = true;
                        continue;
                    }
                    yield new ToolInputDelta($index, $call->id, ToolArguments::toJson($call->arguments));
                    yield new ToolInputEnd($index, $call->id, $call->name, $arguments, $call->metadata);
                }

                if ($response->finishReason !== ProviderFinishReason::Stop) {
                    $reason = $response->finishReason;
                }
            }

            [$sawError, $processFailed] = $responses->getReturn();
            if ($sawError) {
                $reason = ProviderFinishReason::Error;
            } elseif ($reason === ProviderFinishReason::Stop && $callCount > 0) {
                $reason = ProviderFinishReason::ToolUse;
            }

            // A call whose arguments were dropped cannot run: a ToolUse end becomes Error.
            if ($badArguments && $reason === ProviderFinishReason::ToolUse) {
                $reason = ProviderFinishReason::Error;
            }

            $finish = new Finish($reason, '', $usage, $model, $contentBlocks);
        } finally {
            $this->reportUsage(
                $operation,
                $start,
                $model,
                $usage,
                $finish->reason ?? ProviderFinishReason::Error,
                $processFailed,
            );
        }

        yield $finish;
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        // The CLI runs as a raw LLM, so request JSON via a system instruction and
        // best-effort decode the result — no native structured-output protocol.
        $options[UsageReport::OPERATION_OPTION] = 'structured';
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

    /** @param array<string, mixed> $options */
    private function usageOperation(array &$options, string $default): string
    {
        $operation = $options[UsageReport::OPERATION_OPTION] ?? null;
        unset($options[UsageReport::OPERATION_OPTION]);

        return $operation === 'structured' ? 'structured' : $default;
    }

    private function reportUsage(
        string $operation,
        int $start,
        ?string $model,
        ?Usage $usage,
        ProviderFinishReason $reason,
        bool $processFailed,
    ): void {
        if ($this->usageListener === null) {
            return;
        }

        $failed = $processFailed || $reason === ProviderFinishReason::Error;
        $report = new UsageReport(
            operation: $operation,
            provider: 'CliProvider',
            requestedModel: $this->model,
            model: !$failed && $model !== null && $model !== '' ? $model : $this->model,
            usage: $usage,
            requestId: null,
            responseId: null,
            finishReason: $failed ? ProviderFinishReason::Error : $reason,
            durationMs: intdiv((int) hrtime(true) - $start, 1_000_000),
            failed: $failed,
        );

        try {
            $this->usageListener->onUsage($report);
        } catch (\Throwable $e) {
            try {
                $this->logger?->warning('Usage listener failed.', ['exception_class' => $e::class]);
            } catch (\Throwable) {
                // Logging is best-effort so inference outcomes are preserved.
            }
        }
    }

    /**
     * Throw when $bytes of process output pass RequestLimits::$maxResponseBytes.
     */
    private function checkOutputBytes(int $bytes): void
    {
        $max = $this->requestLimits->maxResponseBytes;
        if ($max > 0 && $bytes > $max) {
            throw ProviderException::requestRefused(sprintf('response exceeded %d bytes', $max));
        }
    }

    /**
     * The adapter's responses for one turn: one per streamed stdout fragment that carries
     * anything, or the single adapter response when the vendor cannot stream.
     *
     * @return \Generator<int, Response, mixed, array{bool, bool}> The runtime's stream error flag and the process failure flag.
     */
    private function adapterResponses(CliProcessRequest $request, bool $stream): \Generator
    {
        $processFailed = false;
        if (!$stream) {
            yield $this->adapterChat($request, $processFailed);

            return [false, $processFailed];
        }

        $carry = [];
        $sawError = false;
        $sawLast = false;
        $outputBytes = 0;

        foreach ($this->runtime->stream($request) as $chunk) {
            // Thrown before the fragment is parsed; leaving the loop releases the process stream.
            $outputBytes += strlen($chunk->content);
            $this->checkOutputBytes($outputBytes);

            if ($chunk->error !== null && $chunk->error !== '') {
                $sawError = true;
            }
            if ($chunk->isLast) {
                $sawLast = true;
                $processFailed = $chunk->exitCode !== 0;
            }

            $response = $this->adapter->parseChunk($chunk, $this->model, $carry);
            if ($response !== null) {
                yield $response;
            }
        }

        return [$sawError, !$sawLast || $processFailed];
    }
}
