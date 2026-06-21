<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\CliVendorAdapterInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;

/**
 * Generic provider that drives a CLI binary as an LLM backend.
 *
 * The vendor-specific knowledge (argv shaping, output parsing, model catalog)
 * lives in an injected CliVendorAdapterInterface; the process execution lives
 * in an injected CliRuntimeInterface. This keeps the provider reusable across
 * every CLI vendor (claude, codex, grok, ...) and keeps php-agents free of any
 * concrete subprocess dependency — the host supplies the runtime.
 */
final class CliProvider implements ProviderInterface
{
    public function __construct(
        private string $model,
        private readonly CliVendorAdapterInterface $adapter,
        private readonly CliRuntimeInterface $runtime,
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $request = $this->adapter->buildRequest($messages, $tools, $options, $this->model, false);
        $result = $this->runtime->run($request);

        return $this->adapter->parseResult($result, $this->model);
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        if (!$this->adapter->isStreamingSupported()) {
            // No native streaming — emit the aggregated response as a single chunk.
            yield $this->chat($messages, $tools, $options);

            return;
        }

        $request = $this->adapter->buildRequest($messages, $tools, $options, $this->model, true);

        $carry = [];
        $sawError = null;

        foreach ($this->runtime->stream($request) as $chunk) {
            if ($chunk->error !== null && $chunk->error !== '') {
                $sawError = $chunk->error;
            }

            $response = $this->adapter->parseChunk($chunk, $this->model, $carry);
            if ($response !== null) {
                yield $response;
            }
        }

        if ($sawError !== null) {
            yield new Response(
                content: '',
                finishReason: ProviderFinishReason::Error,
                model: $this->model,
            );
        }
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
}
