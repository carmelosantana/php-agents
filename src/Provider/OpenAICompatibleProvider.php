<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolArguments;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAICompatibleProvider extends AbstractProvider
{
    public function __construct(
        string $model,
        string $baseUrl = 'http://localhost:11434/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        protected readonly string $discoveredProviderName = 'openai',
    ) {
        parent::__construct($model, $baseUrl, $apiKey, $httpClient, $logger);
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $tools = $this->trimToolsToLimit($tools, self::OPENAI_MAX_TOOLS, 'openai-compatible', '/chat/completions');

        // chat() is never a streaming call — stream_options is a streaming-only
        // OpenAI extension. Remove it if a caller (e.g. OllamaProvider) sets it
        // to null to suppress it from the stream() path as well.
        unset($options['stream_options']);

        $payload = $this->buildPayload($messages, $options, false);

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/chat/completions", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        return $this->parseResponse($this->decodeResponse($response));
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $tools = $this->trimToolsToLimit($tools, self::OPENAI_MAX_TOOLS, 'openai-compatible', '/chat/completions');

        // Allow callers to suppress stream_options by passing null (e.g. MistralProvider).
        // Default to include_usage so OpenAI-compatible providers return token counts.
        $streamOptions = array_key_exists('stream_options', $options)
            ? $options['stream_options']
            : ['include_usage' => true];
        unset($options['stream_options']);

        $payload = $this->buildPayload($messages, $options, true);

        if ($streamOptions !== null) {
            $payload['stream_options'] = $streamOptions;
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/chat/completions", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        // Each chunk's choices[0].delta may carry, in this order of yielding:
        //   reasoning | reasoning_content | thinking → ReasoningDelta
        //   refusal                                  → TextDelta; the finish becomes Refusal
        //   content                                  → TextDelta
        //   tool_calls[]                             → fragments keyed by `index`: a fragment with
        //                                              a new id opens a call (ToolInputStart), and
        //                                              each argument fragment yields ToolInputDelta
        // choices[0].finish_reason ends the message. On a tool-use finish every open call yields
        // ToolInputEnd in the order the calls opened. The single Finish waits for usage: it is
        // yielded on the first chunk from the finish chunk onward that carries `usage` (OpenAI
        // sends it in a separate chunk with empty choices), else at the end of the stream.
        // A stream that ends without a finish_reason yields Finish(Error).
        /** @var array<int, array{id: string, name: string, arguments: string}> $calls position → call */
        $calls = [];
        /** @var array<int, int> $openCallAt provider index → position of the call open there */
        $openCallAt = [];
        // Positions of the calls whose arguments passed the byte cap: their fragments are no
        // longer kept or yielded, and the call is dropped at its end.
        /** @var array<int, true> $overCap */
        $overCap = [];
        $refused = false;
        $model = $this->model;
        $usage = null;
        /** @var array{reason: ProviderFinishReason, raw: string}|null $finish */
        $finish = null;
        $keepResponse = false;

        $parser = new SseStreamParser(
            $this->httpClient,
            $response,
            SseStreamParser::DEFAULT_MAX_LINE_BYTES,
            $this->requestLimits->tickInterval,
            $this->requestLimits->idleTimeout,
        );

        try {
            foreach ($parser->events() as $json) {
                if ($json instanceof Tick) {
                    yield $json;

                    continue;
                }

                $model = is_string($json['model'] ?? null) && $json['model'] !== '' ? $json['model'] : $model;
                $chunkUsage = $this->streamChunkUsage($json);
                $usage = $chunkUsage ?? $usage;

                if ($finish === null) {
                    $choice = $json['choices'][0] ?? [];
                    $delta = $choice['delta'] ?? [];

                    $reasoningDelta = $delta['reasoning'] ?? $delta['reasoning_content'] ?? $delta['thinking'] ?? null;
                    if (is_string($reasoningDelta) && $reasoningDelta !== '') {
                        yield new ReasoningDelta($reasoningDelta);
                    }

                    $refusalDelta = $delta['refusal'] ?? null;
                    if (is_string($refusalDelta) && $refusalDelta !== '') {
                        $refused = true;
                        yield new TextDelta($refusalDelta);
                    }

                    $contentDelta = $delta['content'] ?? null;
                    if (is_string($contentDelta) && $contentDelta !== '') {
                        yield new TextDelta($contentDelta);
                    }

                    foreach ($delta['tool_calls'] ?? [] as $fragment) {
                        $index = (int) ($fragment['index'] ?? 0);
                        $id = $fragment['id'] ?? null;
                        // Some servers send arguments as a JSON object rather than a string.
                        $argumentsDelta = ToolArguments::toJson($fragment['function']['arguments'] ?? null);

                        if (is_string($id) && $id !== '' && (!isset($openCallAt[$index]) || $calls[$openCallAt[$index]]['id'] !== $id)) {
                            if (count($calls) >= RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE) {
                                yield $this->toolCallOverflow($usage, $model);

                                return;
                            }
                            $position = count($calls);
                            $openCallAt[$index] = $position;
                            $calls[$position] = ['id' => $id, 'name' => (string) ($fragment['function']['name'] ?? ''), 'arguments' => ''];
                            yield new ToolInputStart($position, $id, $calls[$position]['name']);
                        } elseif (!isset($openCallAt[$index])) {
                            continue; // A fragment for a call that never opened carries nothing usable.
                        }

                        $position = $openCallAt[$index];
                        $call = $calls[$position];
                        if ($argumentsDelta === '' || isset($overCap[$position])) {
                            continue;
                        }
                        if (ToolArguments::overByteCap(strlen($call['arguments']) + strlen($argumentsDelta), $this->requestLimits)) {
                            $overCap[$position] = true;
                            $call['arguments'] = '';
                            $calls[$position] = $call;

                            continue;
                        }
                        $call['arguments'] .= $argumentsDelta;
                        $calls[$position] = $call;
                        yield new ToolInputDelta($position, $call['id'], $argumentsDelta);
                    }

                    $finishReason = $choice['finish_reason'] ?? null;
                    if (is_string($finishReason)) {
                        $finish = ['reason' => $this->streamFinishReason($finishReason, $refused, $calls !== []), 'raw' => $finishReason];

                        if ($finish['reason'] === ProviderFinishReason::ToolUse) {
                            foreach ($calls as $position => $call) {
                                $end = isset($overCap[$position]) ? $this->dropOverCap($call['name']) : $this->endToolInput($position, $call);
                                if ($end === null) {
                                    $finish['reason'] = ProviderFinishReason::Error;
                                } else {
                                    yield $end;
                                }
                            }
                        }
                    }
                }

                if ($finish !== null && $chunkUsage !== null) {
                    yield new Finish($finish['reason'], $finish['raw'], $usage, $model);

                    return;
                }
            }

            yield $finish === null
                ? new Finish(ProviderFinishReason::Error, usage: $usage, model: $model)
                : new Finish($finish['reason'], $finish['raw'], $usage, $model);
        } catch (\Throwable $e) {
            // Only an HTTP status error leaves the response open: the caller reads its error
            // body. Anything else after a successful status (a parser limit, a provider error
            // event) is cancelled below like an abandoned stream.
            $keepResponse = $e instanceof HttpExceptionInterface;

            throw $e;
        } finally {
            // An abandoned generator (the consumer stopped iterating) must not leave the
            // response open, whatever the client does when it is destroyed.
            if (!$keepResponse) {
                $response->cancel();
            }
        }
    }

    /**
     * The finish reason for a streamed finish_reason. A streamed refusal turns a plain
     * Stop or a tool-use finish into Refusal (its tool calls are dropped), and a tool-use
     * finish with no streamed call becomes Stop (ollama/ollama#10976).
     */
    private function streamFinishReason(string $finishReason, bool $refused, bool $hasCalls): ProviderFinishReason
    {
        $mapped = $this->mapFinishReason($finishReason);

        if ($refused && ($mapped === ProviderFinishReason::Stop || $mapped === ProviderFinishReason::ToolUse)) {
            return ProviderFinishReason::Refusal;
        }

        if ($mapped === ProviderFinishReason::ToolUse && !$hasCalls) {
            $this->logger?->debug('tool_calls finish with no pending tool calls — treating as stop');

            return ProviderFinishReason::Stop;
        }

        return $mapped;
    }

    /**
     * The ToolInputEnd for a finished call, or null (logged) when its arguments
     * are not a JSON object. Empty or whitespace arguments decode as `{}`.
     *
     * @param array{id: string, name: string, arguments: string} $call
     */
    private function endToolInput(int $position, array $call): ?ToolInputEnd
    {
        $arguments = ToolArguments::decode($call['arguments'], $this->requestLimits);
        if ($arguments === null) {
            $this->logger?->debug('Tool {tool} arguments ({length} bytes) are not a JSON object; the call is dropped', [
                'tool' => $call['name'],
                'length' => strlen($call['arguments']),
            ]);

            return null;
        }

        return new ToolInputEnd($position, $call['id'], $call['name'], $arguments);
    }

    /**
     * The Finish for a response that opened more than RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE
     * calls: the stream stops there, so no further per-call state is kept.
     */
    private function toolCallOverflow(?Usage $usage, string $model): Finish
    {
        $this->logger?->debug('The response opened more than {max} tool calls; it is abandoned', ['max' => RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE]);

        return new Finish(ProviderFinishReason::Error, 'tool_call_overflow', $usage, $model);
    }

    /**
     * Log a call whose streamed arguments passed the byte cap; it is dropped like one whose
     * arguments are not a JSON object. Always null, the "no ToolInputEnd" of endToolInput().
     */
    private function dropOverCap(string $tool): null
    {
        $this->logger?->debug('Tool {tool} arguments passed the {max}-byte cap; the call is dropped', [
            'tool' => $tool,
            'max' => $this->requestLimits->maxToolArgumentBytes,
        ]);

        return null;
    }

    /**
     * The usage a streamed chunk carries, or null. A subclass reads a server's own
     * usage fields here; the Finish waits for the first chunk where this is not null.
     *
     * @param array<mixed> $json
     */
    protected function streamChunkUsage(array $json): ?Usage
    {
        return is_array($json['usage'] ?? null) ? $this->parseUsage($json['usage']) : null;
    }

    /**
     * Normalised usage from an OpenAI chat `usage` object, for chat() and streaming.
     * prompt_tokens already counts cached input and completion_tokens already counts
     * reasoning; the cached and reasoning figures come from the *_details objects.
     * totalTokens is the provider's total, else prompt + completion. Null when the
     * payload has no token counts.
     *
     * @param array<mixed> $usage
     */
    protected function parseUsage(array $usage): ?Usage
    {
        $count = static fn(mixed $value): ?int => is_int($value) ? $value : null;
        $prompt = $count($usage['prompt_tokens'] ?? null);
        $completion = $count($usage['completion_tokens'] ?? null);
        $total = $count($usage['total_tokens'] ?? null);
        $cached = $count($usage['prompt_tokens_details']['cached_tokens'] ?? null);
        $reasoning = $count($usage['completion_tokens_details']['reasoning_tokens'] ?? null);

        if ($prompt === null && $completion === null && $total === null) {
            return null;
        }

        return new Usage(
            promptTokens: $prompt ?? 0,
            completionTokens: $completion ?? 0,
            totalTokens: $total ?? ($prompt ?? 0) + ($completion ?? 0),
            cacheReadTokens: $cached,
            reasoningTokens: $reasoning,
        );
    }

    /**
     * Caller options override every field except the conversation (`messages`)
     * and the transport flag (`stream`), which the provider owns.
     *
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $options, bool $stream): array
    {
        return [
            'model' => $this->model,
            ...$options,
            'messages' => $this->formatMessages($messages),
            'stream' => $stream,
        ];
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $schemaData = json_decode($schema, true);
        if (!is_array($schemaData)) {
            return $this->chat($messages, [], $options);
        }

        // The OpenAI (and Ollama OpenAI-compat) spec REQUIRES the schema to be
        // wrapped: response_format.json_schema = {name, schema, strict}. A bare
        // schema is silently ignored by the server, so the model returns
        // free-form prose instead of schema-constrained JSON.
        //
        // Accept both shapes, mirroring AnthropicProvider/OpenAIResponsesProvider:
        //   - a bare JSON Schema (the common case), or
        //   - a pre-wrapped {name, schema/parameters, ...} envelope.
        $name = $schemaData['name'] ?? $schemaData['title'] ?? 'structured_output';
        $innerSchema = $schemaData['schema'] ?? $schemaData['parameters'] ?? $schemaData;

        if (!isset($innerSchema['type'])) {
            $innerSchema['type'] = 'object';
        }

        // Strict mode requires every object to be closed and fully required.
        // StrictSchemaNormalizer::qualifies() says whether normalize() can do
        // that without changing what the schema accepts; when it can't (e.g. an open
        // map, a free-form object, a $ref), forward the schema intact with strict:false.
        $strict = StrictSchemaNormalizer::qualifies($innerSchema);
        if ($strict) {
            $innerSchema = StrictSchemaNormalizer::normalize($innerSchema);
        }

        return $this->chat($messages, [], [
            ...$options,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $name,
                    'schema' => $innerSchema,
                    'strict' => $strict,
                ],
            ],
        ]);
    }

    public function models(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", $this->requestLimits->apply([
                'headers' => $this->headers(),
            ]));

            $data = $this->decodeResponse($response);
            $models = [];

                foreach ($this->extractModelEntries($data) as $model) {
                    $definition = $this->buildDiscoveredModelDefinition($model);
                    if ($definition !== null) {
                        $models[] = $definition;
                    }
            }

            return $models;
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch models: {error}', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    protected function extractModelEntries(array $data): array
    {
        $entries = $data['data'] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $model
     */
    protected function buildDiscoveredModelDefinition(array $model): ?ModelDefinition
    {
        return ModelDefinition::fromDiscovery($this->discoveredProviderName, $model);
    }

    public function isAvailable(): bool
    {
        try {
            $this->httpClient->request('GET', "{$this->baseUrl}/models", [...$this->requestLimits->apply([
                'headers' => $this->headers(),
            ]), 'timeout' => 5.0]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->debug('Provider availability check failed: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }

    protected function formatTools(array $tools): array
    {
        return array_map(function (ToolInterface $tool) {
            $schema = $tool->toFunctionSchema();

            // OpenAI requires 'required' to always be present, even for zero-parameter tools
            if (!isset($schema['function']['parameters']['required'])) {
                $schema['function']['parameters']['required'] = [];
            }

            return $schema;
        }, $tools);
    }

    protected function formatMessages(array $messages): array
    {
        return array_map(fn(MessageInterface $msg) => $msg->toArray(), $messages);
    }

    protected function parseResponse(array $data): Response
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $finishReason = $this->mapFinishReason($choice['finish_reason'] ?? 'stop');

        // A call whose arguments are not a JSON object is dropped, and a tool stop then
        // becomes Error, as in the stream.
        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $name = $tc['function']['name'] ?? '';
            $arguments = ToolArguments::fromDecoded($tc['function']['arguments'] ?? null, $this->requestLimits);
            if ($arguments === null) {
                $this->logger?->debug('Tool {tool} arguments are not a JSON object; the call is dropped', ['tool' => $name]);
                if ($finishReason === ProviderFinishReason::ToolUse) {
                    $finishReason = ProviderFinishReason::Error;
                }

                continue;
            }

            $toolCalls[] = new ToolCall(
                id: $tc['id'] ?? '',
                name: $name,
                arguments: $arguments,
            );
        }

        $usage = is_array($data['usage'] ?? null) ? $this->parseUsage($data['usage']) : null;

        // Ollama non-streaming thinking models return a top-level `thinking` field
        // on the message object alongside `content`.
        $reasoning = $message['thinking'] ?? '';

        // A refusal arrives in message.refusal; the person sees its text and
        // no tool call is returned.
        $content = $message['content'] ?? '';
        $refusal = $message['refusal'] ?? null;
        if (is_string($refusal) && $refusal !== '') {
            $content = $refusal;
            $finishReason = ProviderFinishReason::Refusal;
            $toolCalls = [];
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $usage,
            reasoning: is_string($reasoning) ? $reasoning : '',
        );
    }

    protected function mapFinishReason(?string $reason): ProviderFinishReason
    {
        return match ($reason) {
            'stop' => ProviderFinishReason::Stop,
            'tool_calls', 'function_call' => ProviderFinishReason::ToolUse,
            'length' => ProviderFinishReason::MaxTokens,
            'content_filter' => ProviderFinishReason::ContentFilter,
            'error' => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
    }
}
