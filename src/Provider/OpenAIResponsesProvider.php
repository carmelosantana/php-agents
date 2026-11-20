<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
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

/**
 * Provider for OpenAI's Responses API (/v1/responses).
 *
 * Required for Codex models (gpt-5-codex, etc.) that do not support
 * the Chat Completions endpoint. Also works with standard models
 * like gpt-4o and gpt-5 as a forward-compatible replacement.
 *
 * Key differences from Chat Completions:
 * - Endpoint: POST /v1/responses (not /v1/chat/completions)
 * - Request uses `input` array (not `messages`)
 * - Tool results are `function_call_output` items (not role=tool messages)
 * - Response uses `output` array of items (not choices[0].message)
 * - Streaming uses different event types (response.output_text.delta, etc.)
 */
final class OpenAIResponsesProvider extends AbstractProvider
{
    public function __construct(
        string $model,
        string $baseUrl = 'https://api.openai.com/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        private readonly string $discoveredProviderName = 'openai',
    ) {
        parent::__construct($model, $baseUrl, $apiKey, $httpClient, $logger);
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $tools = $this->trimToolsToLimit($tools, self::OPENAI_MAX_TOOLS, 'openai-responses', '/responses');

        $payload = $this->buildPayload($messages, $options);

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/responses", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        return $this->parseResponse($this->decodeResponse($response));
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $tools = $this->trimToolsToLimit($tools, self::OPENAI_MAX_TOOLS, 'openai-responses', '/responses');

        $payload = [...$this->buildPayload($messages, $options), 'stream' => true];

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/responses", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        // Events, in the order they arrive:
        //   response.output_text.delta | response.refusal.delta → TextDelta (a refusal makes the
        //                                                         completed finish Refusal)
        //   response.reasoning_summary_text.delta               → ReasoningDelta
        //   response.output_item.added (function_call)          → ToolInputStart
        //   response.function_call_arguments.delta              → ToolInputDelta
        //   response.output_item.done (function_call)           → ToolInputEnd (the item's own
        //                                                         arguments arrive as one
        //                                                         ToolInputDelta when none streamed)
        // A call's index is its position in this response (the order calls opened); output_index
        // only routes fragments to it. Terminal events, each yielding the single Finish:
        //   response.completed  → Refusal, ToolUse (calls still open end here) or Stop; raw
        //                         reason `completed`
        //   response.incomplete → ContentFilter or MaxTokens; raw reason incomplete_details.reason,
        //                         else `incomplete`
        //   response.failed     → Error with raw reason `failed`; thrown when it carries an error
        //                         message
        // An `error` event is thrown as a ProviderException. A stream that ends without a
        // terminal event yields Finish(Error).
        /** @var array<int, array{id: string, name: string, arguments: string, ended: bool}> $calls position → call */
        $calls = [];
        /** @var array<int, int> $callAt output_index → position */
        $callAt = [];
        // Positions of the calls whose arguments passed the byte cap: their fragments are no
        // longer kept or yielded, and the call is dropped at its end.
        /** @var array<int, true> $overCap */
        $overCap = [];
        $refused = false;
        $invalidArguments = false;
        $model = $this->model;
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

                $eventType = $json['type'] ?? null;
                $responseData = is_array($json['response'] ?? null) ? $json['response'] : [];
                if (is_string($responseData['model'] ?? null) && $responseData['model'] !== '') {
                    $model = $responseData['model'];
                }

                if ($eventType === 'response.output_text.delta' || $eventType === 'response.refusal.delta') {
                    if ($eventType === 'response.refusal.delta') {
                        $refused = true;
                    }
                    $delta = $json['delta'] ?? null;
                    if (is_string($delta) && $delta !== '') {
                        yield new TextDelta($delta);
                    }
                } elseif ($eventType === 'response.reasoning_summary_text.delta') {
                    $delta = $json['delta'] ?? null;
                    if (is_string($delta) && $delta !== '') {
                        yield new ReasoningDelta($delta);
                    }
                } elseif ($eventType === 'response.output_item.added' || $eventType === 'response.output_item.done') {
                    $item = is_array($json['item'] ?? null) ? $json['item'] : [];
                    if (($item['type'] ?? '') !== 'function_call') {
                        continue;
                    }

                    $outputIndex = (int) ($json['output_index'] ?? 0);
                    if (!isset($callAt[$outputIndex])) {
                        $position = count($calls);
                        $callAt[$outputIndex] = $position;
                        $calls[$position] = [
                            'id' => is_string($item['call_id'] ?? null) ? $item['call_id'] : '',
                            'name' => is_string($item['name'] ?? null) ? $item['name'] : '',
                            'arguments' => '',
                            'ended' => false,
                        ];
                        yield new ToolInputStart($position, $calls[$position]['id'], $calls[$position]['name']);
                    }

                    if ($eventType === 'response.output_item.done') {
                        $position = $callAt[$outputIndex];
                        $call = $calls[$position];
                        if ($call['ended']) {
                            continue;
                        }

                        // An id or name the added item lacked comes from the done item.
                        if ($call['id'] === '' && is_string($item['call_id'] ?? null)) {
                            $call['id'] = $item['call_id'];
                        }
                        if ($call['name'] === '' && is_string($item['name'] ?? null)) {
                            $call['name'] = $item['name'];
                        }

                        $itemArguments = ToolArguments::toJson($item['arguments'] ?? null);
                        if ($call['arguments'] === '' && $itemArguments !== '' && !isset($overCap[$position])) {
                            if (ToolArguments::overByteCap(strlen($itemArguments), $this->requestLimits)) {
                                $overCap[$position] = true;
                            } else {
                                $call['arguments'] = $itemArguments;
                                yield new ToolInputDelta($position, $call['id'], $itemArguments);
                            }
                        }

                        $call['ended'] = true;
                        $calls[$position] = $call;
                        $end = $this->endToolInput($position, $call, isset($overCap[$position]));
                        if ($end === null) {
                            $invalidArguments = true;
                        } else {
                            yield $end;
                        }
                    }
                } elseif ($eventType === 'response.function_call_arguments.delta') {
                    $position = $callAt[(int) ($json['output_index'] ?? 0)] ?? null;
                    $delta = ToolArguments::toJson($json['delta'] ?? null);
                    if ($position === null || $calls[$position]['ended'] || $delta === '' || isset($overCap[$position])) {
                        continue; // Fragments for a call that is not open carry nothing usable.
                    }
                    $call = $calls[$position];
                    if (ToolArguments::overByteCap(strlen($call['arguments']) + strlen($delta), $this->requestLimits)) {
                        $overCap[$position] = true;
                        $call['arguments'] = '';
                        $calls[$position] = $call;

                        continue;
                    }

                    $call['arguments'] .= $delta;
                    $calls[$position] = $call;
                    yield new ToolInputDelta($position, $calls[$position]['id'], $delta);
                } elseif ($eventType === 'response.completed') {
                    $reason = $this->mapStatus(['status' => 'completed'], $calls !== [], $refused);

                    if ($reason === ProviderFinishReason::ToolUse) {
                        foreach ($calls as $position => $call) {
                            if ($call['ended']) {
                                continue;
                            }
                            $end = $this->endToolInput($position, $call, isset($overCap[$position]));
                            if ($end === null) {
                                $invalidArguments = true;
                            } else {
                                yield $end;
                            }
                        }

                        if ($invalidArguments) {
                            $reason = ProviderFinishReason::Error;
                        }
                    }

                    yield new Finish($reason, 'completed', $this->parseUsage($responseData), $model);

                    return;
                } elseif ($eventType === 'response.incomplete' || $eventType === 'response.failed') {
                    $status = $eventType === 'response.failed' ? 'failed' : 'incomplete';
                    $error = is_array($responseData['error'] ?? null) ? $responseData['error'] : [];
                    $errorMessage = $error['message'] ?? null;

                    if ($status === 'failed' && is_string($errorMessage) && $errorMessage !== '') {
                        throw ProviderException::streamError($this->errorCode($error['code'] ?? null, 'response.failed'), $errorMessage);
                    }

                    $incompleteReason = $responseData['incomplete_details']['reason'] ?? null;

                    yield new Finish(
                        $this->mapStatus([...$responseData, 'status' => $status], false, false),
                        $status === 'incomplete' && is_string($incompleteReason) && $incompleteReason !== '' ? $incompleteReason : $status,
                        $this->parseUsage($responseData),
                        $model,
                    );

                    return;
                } elseif ($eventType === 'error') {
                    $errorMessage = $json['message'] ?? null;

                    throw ProviderException::streamError(
                        $this->errorCode($json['code'] ?? null, 'error'),
                        is_string($errorMessage) && $errorMessage !== '' ? $errorMessage : 'unknown stream error',
                    );
                }
            }

            yield new Finish(ProviderFinishReason::Error, model: $model);
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
     * The ToolInputEnd for a finished call, or null (logged) when its arguments
     * are not a JSON object or passed the byte cap while streaming ($overCap). Empty or
     * whitespace arguments decode as `{}`.
     *
     * @param array{id: string, name: string, arguments: string, ended: bool} $call
     */
    private function endToolInput(int $position, array $call, bool $overCap = false): ?ToolInputEnd
    {
        if ($overCap) {
            $this->logger?->debug('Tool {tool} arguments passed the {max}-byte cap; the call is dropped', [
                'tool' => $call['name'],
                'max' => $this->requestLimits->maxToolArgumentBytes,
            ]);

            return null;
        }

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
     * A stream error's code when it is a string, else the fallback.
     */
    private function errorCode(mixed $code, string $fallback): string
    {
        return is_string($code) && $code !== '' ? $code : $fallback;
    }

    /**
     * Caller options override every field except the conversation (`input`)
     * and the transport flag (`stream`), which the provider owns; stream()
     * sets `stream` itself.
     *
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $options): array
    {
        unset($options['stream']);

        return [
            'model' => $this->model,
            ...$options,
            'input' => $this->formatMessages($messages),
        ];
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $schemaData = json_decode($schema, true);
        if ($schemaData === null) {
            return $this->chat($messages, [], $options);
        }

        $toolName = $schemaData['name'] ?? 'structured_output';
        $description = $schemaData['description'] ?? 'Generate structured output matching the schema.';
        $inputSchema = $schemaData['schema'] ?? $schemaData['parameters'] ?? $schemaData;

        if (!isset($inputSchema['type'])) {
            $inputSchema['type'] = 'object';
        }

        $payload = $this->buildPayload($messages, [
            'tools' => [
                [
                    'type' => 'function',
                    'name' => $toolName,
                    'description' => $description,
                    'parameters' => $inputSchema,
                ],
            ],
            'tool_choice' => ['type' => 'function', 'name' => $toolName],
            ...$options,
        ]);

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/responses", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        $data = $this->decodeResponse($response);

        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'function_call' && ($item['name'] ?? '') === $toolName) {
                return json_decode($item['arguments'] ?? '{}', true) ?? [];
            }
        }

        return $this->parseResponse($data);
    }

    public function models(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", $this->requestLimits->apply([
                'headers' => $this->headers(),
            ]));

            $data = $this->decodeResponse($response);
            $models = [];

            foreach ($data['data'] ?? [] as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $definition = ModelDefinition::fromDiscovery($this->discoveredProviderName, $model);
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
        return array_map(function (ToolInterface $tool): array {
            $schema = $tool->toFunctionSchema();
            $parameters = $schema['function']['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];

            // Strict mode only when normalize() can close the schema without changing
            // what it accepts (StrictSchemaNormalizer::qualifies() documents what it
            // can't); anything else, e.g. a raw MCP schema with a free-form object,
            // goes out without strict-mode rewriting.
            $strict = StrictSchemaNormalizer::qualifies($parameters);

            if ($strict) {
                $parameters = StrictSchemaNormalizer::normalize($parameters);
            }

            return [
                'type' => 'function',
                'name' => $schema['function']['name'],
                'description' => $schema['function']['description'],
                'parameters' => $parameters,
                'strict' => $strict,
            ];
        }, $tools);
    }

    protected function formatMessages(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            match ($message->role()) {
                Role::System => $input[] = [
                    'role' => 'system',
                    'content' => $this->stringifyContent($message->content()),
                ],
                Role::User => $input[] = [
                    'role' => 'user',
                    'content' => $message->content(),
                ],
                Role::Assistant => $this->formatAssistantMessage($message, $input),
                Role::Tool => $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $message->toolCallId() ?? '',
                    'output' => $this->stringifyContent($message->content()),
                ],
            };
        }

        return $input;
    }

    protected function parseResponse(array $data): Response
    {
        $content = '';
        $reasoning = '';
        $toolCalls = [];
        $refused = false;
        $droppedCalls = false;

        foreach ($data['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $block) {
                    $blockType = $block['type'] ?? '';
                    if ($blockType === 'output_text') {
                        $content .= $block['text'] ?? '';
                    } elseif ($blockType === 'refusal') {
                        // The person sees the refusal text.
                        $content .= $block['refusal'] ?? '';
                        $refused = true;
                    }
                }
            } elseif ($type === 'reasoning') {
                // o1/o3/o4 reasoning summary — extract text from summary blocks
                foreach ($item['summary'] ?? [] as $block) {
                    $reasoning .= $block['text'] ?? '';
                }
            } elseif ($type === 'function_call') {
                $arguments = ToolArguments::fromDecoded($item['arguments'] ?? null, $this->requestLimits);
                if ($arguments === null) {
                    $this->logger?->debug('Tool {tool} arguments are not a JSON object; the call is dropped', ['tool' => $item['name'] ?? '']);
                    $droppedCalls = true;

                    continue;
                }

                $toolCalls[] = new ToolCall(
                    id: $item['call_id'] ?? '',
                    name: $item['name'] ?? '',
                    arguments: $arguments,
                );
            }
        }

        // A dropped call turns what would have been a tool stop into Error, as in the stream.
        $finishReason = $this->mapStatus($data, $toolCalls !== [] || $droppedCalls, $refused);
        if ($droppedCalls && $finishReason === ProviderFinishReason::ToolUse) {
            $finishReason = ProviderFinishReason::Error;
        }

        // Tool calls run only from a completed response that is not a
        // refusal; an incomplete or failed one may carry truncated arguments
        // (as in the stream path).
        if ($refused || ($data['status'] ?? 'completed') !== 'completed') {
            $toolCalls = [];
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $this->parseUsage($data),
            reasoning: $reasoning,
        );
    }

    /**
     * Map a response's `status` (and `incomplete_details.reason`) to a finish reason.
     *
     * @param array<string, mixed> $data
     */
    private function mapStatus(array $data, bool $hasToolCalls, bool $refused): ProviderFinishReason
    {
        return match ($data['status'] ?? 'completed') {
            'completed' => match (true) {
                $refused => ProviderFinishReason::Refusal,
                $hasToolCalls => ProviderFinishReason::ToolUse,
                default => ProviderFinishReason::Stop,
            },
            'incomplete' => ($data['incomplete_details']['reason'] ?? null) === 'content_filter'
                ? ProviderFinishReason::ContentFilter
                : ProviderFinishReason::MaxTokens,
            'failed' => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
    }

    /**
     * Format an assistant message for the Responses API input array.
     *
     * Text content becomes a standard assistant message. Tool calls
     * become separate function_call items with their own call_id.
     *
     * @param array<array<string, mixed>> &$input
     */
    private function formatAssistantMessage(MessageInterface $message, array &$input): void
    {
        $content = $message->content();
        if (is_string($content) && $content !== '') {
            $input[] = [
                'role' => 'assistant',
                'content' => $content,
            ];
        }

        foreach ($message->toolCalls() as $toolCall) {
            $input[] = [
                'type' => 'function_call',
                'call_id' => $toolCall->id,
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: new \stdClass()),
            ];
        }
    }

    /**
     * Normalised usage from a Responses API response, for chat() and streaming.
     * input_tokens already counts cached input and output_tokens already counts
     * reasoning; the cached and reasoning figures come from the *_details objects.
     * totalTokens is the provider's total, else input + output. Null when the
     * response has no token counts.
     *
     * @param array<mixed> $data
     */
    private function parseUsage(array $data): ?Usage
    {
        $usage = $data['usage'] ?? null;
        if (!is_array($usage)) {
            return null;
        }

        $count = static fn(mixed $value): ?int => is_int($value) ? $value : null;
        $prompt = $count($usage['input_tokens'] ?? null);
        $completion = $count($usage['output_tokens'] ?? null);
        $total = $count($usage['total_tokens'] ?? null);
        $cached = $count($usage['input_tokens_details']['cached_tokens'] ?? null);
        $reasoning = $count($usage['output_tokens_details']['reasoning_tokens'] ?? null);

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
     * Ensure content is a string for contexts that require it.
     *
     * @param string|array<mixed> $content
     */
    private function stringifyContent(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return json_encode($content) ?: '';
    }
}
