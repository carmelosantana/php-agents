<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class AnthropicProvider extends AbstractProvider
{
    private const MODELS_MAX_PAGES = 10;

    /** Constraint keywords that native structured outputs reject. */
    private const UNSUPPORTED_SCHEMA_KEYWORDS = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength'];

    /** Keywords whose value is a schema, a map of schemas or a list of schemas. */
    private const SCHEMA_KEYWORDS = ['properties', '$defs', 'definitions', 'items', 'prefixItems', 'anyOf', 'allOf', 'oneOf'];

    /** Keywords the preparer does not walk or Anthropic cannot take; a schema using one is not representable. */
    private const UNWALKED_SCHEMA_KEYWORDS = ['not', 'if', 'then', 'else', 'dependentSchemas', 'patternProperties', 'contains', 'propertyNames', 'unevaluatedProperties', '$dynamicRef', '$recursiveRef', 'dependencies', 'unevaluatedItems', 'additionalItems', 'contentSchema'];

    private string $apiVersion = '2023-06-01';

    public function __construct(
        string $model = 'claude-sonnet-5-5',
        string $baseUrl = 'https://api.anthropic.com/v1',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            logger: $logger,
        );
    }

    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
        ];
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $payload = $this->buildPayload($messages, $tools, $options);

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        $parsed = $this->parseResponse($this->decodeResponse($response));

        return new Response(
            content: $parsed->content,
            finishReason: $parsed->finishReason,
            toolCalls: $parsed->toolCalls,
            model: $parsed->model,
            usage: $parsed->usage,
            reasoning: $parsed->reasoning,
            contentBlocks: $this->rawContentBlocks($response),
        );
    }

    /**
     * The response's `content` blocks decoded from the raw body with objects
     * kept as stdClass, so `{}` and `[]` (and numeric-key objects) encode back
     * exactly. Only each top-level block becomes an array. decodeResponse()
     * has already read the body, refused a redirect and thrown on 4xx/5xx.
     *
     * @return list<array<string, mixed>>
     */
    private function rawContentBlocks(ResponseInterface $response): array
    {
        $raw = json_decode($response->getContent());
        $content = $raw instanceof \stdClass ? ($raw->content ?? null) : null;
        if (!is_array($content)) {
            return [];
        }

        $blocks = [];
        foreach ($content as $block) {
            if ($block instanceof \stdClass) {
                /** @var array<string, mixed> $fields */
                $fields = get_object_vars($block);
                $blocks[] = $fields;
            }
        }

        return $blocks;
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $payload = [...$this->buildPayload($messages, $tools, $options), 'stream' => true];

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]));

        // Anthropic's stream, by the `type` field of each data payload:
        //   message_start        → model and input (prompt) token usage
        //   content_block_start  → opens the block at `index` (text, thinking, tool_use);
        //                          redacted_thinking arrives complete here, with `data`
        //   content_block_delta  → text_delta / thinking_delta are yielded as they arrive and
        //                          kept on the block; signature_delta is kept on the thinking
        //                          block; input_json_delta accumulates the tool's arguments
        //   content_block_stop   → block finalized
        //   message_delta        → may repeat; carries stop_reason and cumulative output_tokens
        //   message_stop         → end of message: exactly one terminal chunk is yielded
        //   error                → thrown as a ProviderException
        // A stream that ends before message_stop yields a terminal Error chunk.
        // The terminal chunk carries the rebuilt blocks (contentBlocks) so the
        // agent can send thinking and its signature back unchanged.
        /** @var array<int, array{type: string, id: string, name: string, json: string, text: string, thinking: string, signature: string, data: string}> $blocks */
        $blocks = [];
        $currentBlockIndex = -1;
        $model = $this->model;
        $promptTokens = null;
        $completionTokens = null;
        $stopReason = null;

        $parser = new SseStreamParser($this->httpClient, $response);

        foreach ($parser->events() as $json) {
            if ($json instanceof Tick) {
                continue; // A tick carries no data.
            }

            $eventType = $json['type'] ?? null;

            if ($eventType === 'content_block_start') {
                $currentBlockIndex = $json['index'] ?? 0;
                $block = $json['content_block'] ?? [];
                $blocks[$currentBlockIndex] = [
                    'type' => $block['type'] ?? 'text',
                    'id' => $block['id'] ?? '',
                    'name' => $block['name'] ?? '',
                    'json' => '',
                    'text' => '',
                    'thinking' => '',
                    'signature' => '',
                    'data' => $block['data'] ?? '',
                ];
            } elseif ($eventType === 'content_block_delta') {
                $delta = $json['delta'] ?? [];
                $index = $json['index'] ?? $currentBlockIndex;
                $deltaType = $delta['type'] ?? '';

                if ($deltaType === 'input_json_delta' && isset($blocks[$index])) {
                    $blocks[$index]['json'] .= $delta['partial_json'] ?? '';
                } elseif ($deltaType === 'signature_delta' && isset($blocks[$index])) {
                    $blocks[$index]['signature'] .= $delta['signature'] ?? '';
                } elseif ($deltaType === 'thinking_delta' && isset($delta['thinking'])) {
                    if (isset($blocks[$index])) {
                        $blocks[$index]['thinking'] .= $delta['thinking'];
                    }
                    yield new Response(
                        content: '',
                        finishReason: ProviderFinishReason::Stop,
                        toolCalls: [],
                        model: $model,
                        reasoning: $delta['thinking'],
                    );
                } elseif ($deltaType === 'text_delta' && isset($delta['text'])) {
                    if (isset($blocks[$index])) {
                        $blocks[$index]['text'] .= $delta['text'];
                    }
                    yield new Response(
                        content: $delta['text'],
                        finishReason: ProviderFinishReason::Stop,
                        toolCalls: [],
                        model: $model,
                    );
                }
            } elseif ($eventType === 'message_start') {
                $model = $json['message']['model'] ?? $model;
                $promptTokens = $json['message']['usage']['input_tokens'] ?? $promptTokens;
            } elseif ($eventType === 'message_delta') {
                $stopReason = $json['delta']['stop_reason'] ?? $stopReason;
                $completionTokens = $json['usage']['output_tokens'] ?? $completionTokens;
            } elseif ($eventType === 'message_stop') {
                yield $this->streamTerminal($stopReason, $blocks, $model, $promptTokens, $completionTokens);

                return;
            } elseif ($eventType === 'error') {
                throw ProviderException::streamError(
                    (string) ($json['error']['type'] ?? 'error'),
                    (string) ($json['error']['message'] ?? 'unknown stream error'),
                );
            }
        }

        // EOF before message_stop: the message is incomplete, whatever arrived.
        yield $this->streamTerminal(null, [], $model, $promptTokens, $completionTokens);
    }

    /**
     * The single chunk that ends a stream. No stop reason means the message
     * did not finish (Error). Tool calls are emitted only for ToolUse, and only
     * when every tool's arguments decode to a JSON object; otherwise Error.
     * Content blocks are kept only on a Stop or ToolUse end.
     *
     * @param array<int, array{type: string, id: string, name: string, json: string, text: string, thinking: string, signature: string, data: string}> $blocks
     */
    private function streamTerminal(?string $stopReason, array $blocks, string $model, ?int $promptTokens, ?int $completionTokens): Response
    {
        $finishReason = $stopReason === null ? ProviderFinishReason::Error : $this->mapStopReason($stopReason);
        $toolCalls = [];

        if ($finishReason === ProviderFinishReason::ToolUse) {
            foreach ($blocks as $block) {
                if ($block['type'] !== 'tool_use') {
                    continue;
                }

                $arguments = $this->decodeToolArguments($block['json']);
                if ($arguments === null) {
                    $this->logger?->debug('Anthropic tool {tool} arguments ({length} bytes) are not a JSON object; ending the stream with Error', [
                        'tool' => $block['name'],
                        'length' => strlen($block['json']),
                    ]);
                    $finishReason = ProviderFinishReason::Error;
                    $toolCalls = [];
                    break;
                }

                $toolCalls[] = new ToolCall(id: $block['id'], name: $block['name'], arguments: $arguments);
            }
        }

        $usage = null;
        if ($promptTokens !== null || $completionTokens !== null) {
            $usage = new Usage(
                promptTokens: $promptTokens ?? 0,
                completionTokens: $completionTokens ?? 0,
                totalTokens: ($promptTokens ?? 0) + ($completionTokens ?? 0),
            );
        }

        return new Response(
            content: '',
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $model,
            usage: $usage,
            contentBlocks: $finishReason === ProviderFinishReason::Stop || $finishReason === ProviderFinishReason::ToolUse
                ? $this->rebuildContentBlocks($blocks)
                : [],
        );
    }

    /**
     * Rebuild the streamed blocks in index order, in the shape the Messages API
     * returns them. Any block type other than thinking, redacted_thinking, text
     * and tool_use, or tool JSON that is not an object, gives [] so that replay
     * falls back to re-formatting the message without thinking.
     *
     * @param array<int, array{type: string, id: string, name: string, json: string, text: string, thinking: string, signature: string, data: string}> $blocks
     * @return list<array<string, mixed>>
     */
    private function rebuildContentBlocks(array $blocks): array
    {
        ksort($blocks);
        $rebuilt = [];

        foreach ($blocks as $block) {
            // A text block that got no deltas would be sent back as an empty
            // text block, which the Messages API rejects.
            if ($block['type'] === 'text' && $block['text'] === '') {
                continue;
            }

            $rebuiltBlock = match ($block['type']) {
                'thinking' => ['type' => 'thinking', 'thinking' => $block['thinking'], 'signature' => $block['signature']],
                'redacted_thinking' => ['type' => 'redacted_thinking', 'data' => $block['data']],
                'text' => ['type' => 'text', 'text' => $block['text']],
                'tool_use' => $this->rebuildToolUse($block),
                default => null,
            };

            if ($rebuiltBlock === null) {
                return [];
            }

            $rebuilt[] = $rebuiltBlock;
        }

        return $rebuilt;
    }

    /**
     * @param array{id: string, name: string, json: string} $block
     * @return array<string, mixed>|null null when the arguments are not a JSON object
     */
    private function rebuildToolUse(array $block): ?array
    {
        $input = $block['json'] === '' ? new \stdClass() : json_decode($block['json']);

        return $input instanceof \stdClass
            ? ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => $input]
            : null;
    }

    /**
     * Decode a tool's accumulated `partial_json`. An empty string is `{}`;
     * anything that is not a JSON object (truncated, array, scalar) is null.
     *
     * @return array<string, mixed>|null
     */
    private function decodeToolArguments(string $json): ?array
    {
        if ($json === '') {
            return [];
        }

        if (!json_decode($json) instanceof \stdClass) {
            return null;
        }

        /** @var array<string, mixed> */
        return json_decode($json, true);
    }

    private function mapStopReason(?string $stopReason): ProviderFinishReason
    {
        return match ($stopReason) {
            'end_turn', 'stop_sequence' => ProviderFinishReason::Stop,
            'tool_use' => ProviderFinishReason::ToolUse,
            'max_tokens' => ProviderFinishReason::MaxTokens,
            'refusal' => ProviderFinishReason::Refusal,
            'pause_turn' => ProviderFinishReason::PauseTurn,
            'model_context_window_exceeded' => ProviderFinishReason::ContextWindowExceeded,
            default => $this->unknownStopReason($stopReason),
        };
    }

    private function unknownStopReason(?string $stopReason): ProviderFinishReason
    {
        if ($stopReason !== null) {
            $this->logger?->debug('Unknown Anthropic stop_reason {stop_reason}; treating it as stop', ['stop_reason' => $stopReason]);
        }

        return ProviderFinishReason::Stop;
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        // Native structured outputs: the model answers with one text block of
        // JSON matching the schema. A forced tool_choice returns a 400 on
        // current models, so none is sent, not even the caller's.
        // Decoded with objects kept as stdClass, so `{}` encodes back as `{}`.
        $schemaData = json_decode($schema);
        if (!$schemaData instanceof \stdClass && !is_array($schemaData)) {
            return $this->chat($messages, [], $options);
        }

        // An envelope's name and description are not sent.
        $outputSchema = $schemaData instanceof \stdClass
            ? ($schemaData->schema ?? $schemaData->parameters ?? $schemaData)
            : $schemaData;

        unset($options['tool_choice']);
        $outputConfig = is_array($options['output_config'] ?? null) ? $options['output_config'] : [];
        unset($outputConfig['format']);

        $root = $outputSchema instanceof \stdClass ? clone $outputSchema : $outputSchema;
        if ($root instanceof \stdClass && !isset($root->type)) {
            $root->type = 'object';
        }

        $prepared = $root instanceof \stdClass && $this->hasRepresentableRefs($root)
            ? $this->prepareAnthropicSchema($root)
            : null;
        if ($prepared === null) {
            return $this->structuredWithoutNativeSchema($messages, $outputSchema, $outputConfig, $options);
        }

        $options['output_config'] = [
            ...$outputConfig,
            'format' => ['type' => 'json_schema', 'schema' => $prepared],
        ];

        $response = $this->httpClient->request('POST', "{$this->baseUrl}/messages", $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $this->buildPayload($messages, [], $options),
        ]));

        $data = $this->decodeResponse($response);

        if ($this->mapStopReason($data['stop_reason'] ?? null) === ProviderFinishReason::Stop) {
            foreach ($data['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $decoded = json_decode($block['text'] ?? '', true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                    break;
                }
            }
        }

        return $this->parseResponse($data);
    }

    /**
     * Best-effort JSON for a schema native structured outputs cannot take: no
     * output_config.format, and one extra user instruction with the schema.
     *
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $outputConfig The caller's output_config without `format`
     * @param array<string, mixed> $options
     */
    private function structuredWithoutNativeSchema(array $messages, mixed $outputSchema, array $outputConfig, array $options): mixed
    {
        unset($options['output_config']);
        if ($outputConfig !== []) {
            $options['output_config'] = $outputConfig;
        }

        $messages[] = new UserMessage(
            "Respond with only a JSON value that matches this JSON Schema, with no other text:\n" . json_encode($outputSchema),
        );

        $response = $this->chat($messages, [], $options);

        if ($response->finishReason === ProviderFinishReason::Stop) {
            foreach ($response->contentBlocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $decoded = json_decode(is_string($block['text'] ?? null) ? $block['text'] : '', true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                    break;
                }
            }
        }

        return $response;
    }

    /**
     * Prepare a JSON Schema for Anthropic native structured outputs: close
     * every object that lists `properties` and has no `additionalProperties`
     * (keeping `required` as written, adding no null types) and strip the
     * numeric and string constraint keywords the API rejects.
     *
     * Returns null when the schema cannot be represented: an open object
     * (no `properties` with `additionalProperties` missing or true, or
     * `additionalProperties` set to a schema), or a value that is not a
     * schema object where a schema is expected.
     */
    private function prepareAnthropicSchema(mixed $node): ?\stdClass
    {
        if (!$node instanceof \stdClass) {
            return null;
        }

        $prepared = new \stdClass();
        foreach (get_object_vars($node) as $key => $value) {
            if (in_array($key, self::UNWALKED_SCHEMA_KEYWORDS, true)) {
                return null;
            }

            if (in_array($key, self::UNSUPPORTED_SCHEMA_KEYWORDS, true)) {
                continue;
            }

            $value = match ($key) {
                'properties', '$defs', 'definitions' => $this->prepareSchemaMap($value),
                'items' => $this->prepareAnthropicSchema($value),
                'prefixItems', 'anyOf', 'allOf', 'oneOf' => $this->prepareSchemaList($value),
                default => $value,
            };

            if ($value === null && in_array($key, self::SCHEMA_KEYWORDS, true)) {
                return null;
            }

            $prepared->{$key} = $value;
        }

        $type = $node->type ?? null;
        $isObject = $type === 'object'
            || (is_array($type) && in_array('object', $type, true))
            || property_exists($node, 'properties')
            || property_exists($node, 'additionalProperties');

        if (!$isObject) {
            return $prepared;
        }

        if (!property_exists($node, 'additionalProperties')) {
            if (!property_exists($node, 'properties')) {
                return null;
            }
            $prepared->additionalProperties = false;
        } elseif ($node->additionalProperties !== false) {
            return null;
        }

        return $prepared;
    }

    /**
     * Whether every `$ref` in the schema is a local `#/$defs/X` or
     * `#/definitions/X` pointer to an existing definition and the definitions
     * reference each other without a cycle. Anthropic rejects recursive
     * schemas, so a self-reference or a mutual one is not representable.
     */
    private function hasRepresentableRefs(\stdClass $root): bool
    {
        /** @var array<string, mixed> $definitions Definition key ("$defs/X" or "definitions/X") to schema */
        $definitions = [];
        foreach (['$defs', 'definitions'] as $keyword) {
            if (isset($root->{$keyword}) && $root->{$keyword} instanceof \stdClass) {
                foreach (get_object_vars($root->{$keyword}) as $name => $schema) {
                    $definitions[$keyword . '/' . $name] = $schema;
                }
            }
        }

        /** @var array<string, list<string>> $edges */
        $edges = [];
        foreach ($definitions as $key => $schema) {
            $refs = $this->collectRefs($schema);
            if ($refs === null) {
                return false;
            }
            $edges[$key] = $refs;
        }

        $rootRefs = $this->collectRefs($root);
        if ($rootRefs === null) {
            return false;
        }

        foreach ([...$rootRefs, ...array_merge(...array_values($edges))] as $target) {
            if (!array_key_exists($target, $definitions)) {
                return false;
            }
        }

        // Depth-first search: a definition met again while still on the path is a cycle.
        /** @var array<string, 'visiting'|'done'> $state */
        $state = [];
        $visit = function (string $key) use (&$visit, &$state, $edges): bool {
            if (($state[$key] ?? null) === 'done') {
                return true;
            }
            if (($state[$key] ?? null) === 'visiting') {
                return false;
            }
            $state[$key] = 'visiting';
            foreach ($edges[$key] as $target) {
                if (!$visit($target)) {
                    return false;
                }
            }
            $state[$key] = 'done';

            return true;
        };

        foreach (array_keys($edges) as $key) {
            if (!$visit($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every `$ref` under a node, as a definition key ("$defs/X" or
     * "definitions/X"). Null when a `$ref` is not a local pointer to a
     * top-level definition, such as `#`, an external URL or a deeper path.
     *
     * @return list<string>|null
     */
    private function collectRefs(mixed $node): ?array
    {
        if (!$node instanceof \stdClass && !is_array($node)) {
            return [];
        }

        $refs = [];
        foreach ($node instanceof \stdClass ? get_object_vars($node) : $node as $key => $value) {
            if ($key === '$ref' && $node instanceof \stdClass && !$value instanceof \stdClass) {
                if (!is_string($value) || preg_match('@^#/(\$defs|definitions)/([^/~%]+)$@D', $value, $match) !== 1) {
                    return null;
                }
                $refs[] = $match[1] . '/' . $match[2];
                continue;
            }

            $nested = $this->collectRefs($value);
            if ($nested === null) {
                return null;
            }
            $refs = [...$refs, ...$nested];
        }

        return $refs;
    }

    /**
     * @return \stdClass|null A map of name to prepared schema; null when any entry is not representable
     */
    private function prepareSchemaMap(mixed $map): ?\stdClass
    {
        if (!$map instanceof \stdClass) {
            return null;
        }

        $prepared = new \stdClass();
        foreach (get_object_vars($map) as $name => $schema) {
            $schema = $this->prepareAnthropicSchema($schema);
            if ($schema === null) {
                return null;
            }
            $prepared->{$name} = $schema;
        }

        return $prepared;
    }

    /**
     * @return list<\stdClass>|null The prepared schemas; null when any entry is not representable
     */
    private function prepareSchemaList(mixed $list): ?array
    {
        if (!is_array($list) || !array_is_list($list)) {
            return null;
        }

        $prepared = [];
        foreach ($list as $schema) {
            $schema = $this->prepareAnthropicSchema($schema);
            if ($schema === null) {
                return null;
            }
            $prepared[] = $schema;
        }

        return $prepared;
    }

    /**
     * Build the Messages API payload. Caller options override every field
     * except the conversation (`messages`) and the transport flag (`stream`),
     * which the provider owns; stream() sets `stream` itself. `system` is
     * also the provider's when the conversation has a system message, and
     * `tools` when the $tools argument is not empty.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $tools, array $options): array
    {
        [$systemPrompt, $formattedMessages] = $this->extractSystemAndMessages($messages);

        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        unset($options['stream']);
        if ($this->hasSystemMessage($messages)) {
            unset($options['system']);
        }
        if (!empty($tools)) {
            unset($options['tools']);
        }

        return [...$payload, ...$options, 'messages' => $formattedMessages];
    }

    /** @param MessageInterface[] $messages */
    private function hasSystemMessage(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message->role() === Role::System) {
                return true;
            }
        }

        return false;
    }

    public function models(): array
    {
        try {
            $models = [];
            $afterId = null;

            for ($page = 0; $page < self::MODELS_MAX_PAGES; $page++) {
                $query = $afterId === null ? ['limit' => 1000] : ['after_id' => $afterId, 'limit' => 1000];

                $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", [...$this->requestLimits->apply([
                    'headers' => $this->headers(),
                    'query' => $query,
                ]), 'timeout' => 10.0]);

                $data = $this->decodeResponse($response);

                foreach ($data['data'] ?? [] as $model) {
                    if (is_array($model) && is_string($model['id'] ?? null) && $model['id'] !== '') {
                        $models[] = $this->mapApiModel($model);
                    }
                }

                $lastId = $data['last_id'] ?? null;
                if (($data['has_more'] ?? false) !== true || !is_string($lastId) || $lastId === '' || $lastId === $afterId) {
                    break;
                }
                $afterId = $lastId;
            }

            if (!empty($models)) {
                return $models;
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch Anthropic models: {error}', ['error' => $e->getMessage()]);
            // Fall through to static list
        }

        // Static fallback — kept up-to-date with major releases
        return array_map(
            static fn(array $model): ModelDefinition => new ModelDefinition(
                id: $model[0],
                name: $model[1],
                provider: 'anthropic',
                capabilities: [ModelCapability::Text, ModelCapability::Image, ModelCapability::Tools, ModelCapability::Reasoning],
                reasoning: true,
                contextWindow: $model[2],
                maxTokens: $model[3],
                family: 'claude',
                toolCalls: true,
                vision: true,
                thinking: true,
                metadataSource: 'static-fallback',
                fieldSources: ['contextWindow' => 'static-fallback', 'maxTokens' => 'static-fallback'],
            ),
            [
                ['claude-opus-5-5', 'Claude Opus 5.5', 1_000_000, 128_000],
                ['claude-sonnet-5-5', 'Claude Sonnet 5.5', 1_000_000, 128_000],
                ['claude-haiku-5-5', 'Claude Haiku 5.5', 1_000_000, 128_000],
                ['claude-fable-5-2', 'Claude Fable 5.2', 1_000_000, 128_000],
                ['claude-haiku-4-5', 'Claude Haiku 4.5', 200_000, 64_000],
            ],
        );
    }

    /**
     * Map one entry of the Models API. Limits and capabilities may be null or missing.
     *
     * @param array<string, mixed> $model
     */
    private function mapApiModel(array $model): ModelDefinition
    {
        $id = (string) $model['id'];
        $contextWindow = $model['max_input_tokens'] ?? null;
        $maxTokens = $model['max_tokens'] ?? null;
        $hasContextWindow = is_int($contextWindow) && $contextWindow > 0;
        $hasMaxTokens = is_int($maxTokens) && $maxTokens > 0;

        $capabilities = is_array($model['capabilities'] ?? null) ? $model['capabilities'] : [];
        $vision = ($capabilities['image_input']['supported'] ?? null) === true;
        $thinking = ($capabilities['thinking']['supported'] ?? null) === true;

        $name = $model['display_name'] ?? null;

        return new ModelDefinition(
            id: $id,
            name: is_string($name) && $name !== '' ? $name : $id,
            provider: 'anthropic',
            capabilities: array_values(array_filter([
                ModelCapability::Text,
                $vision ? ModelCapability::Image : null,
                ModelCapability::Tools,
                $thinking ? ModelCapability::Reasoning : null,
            ])),
            reasoning: $thinking,
            contextWindow: $hasContextWindow ? $contextWindow : 200000,
            maxTokens: $hasMaxTokens ? $maxTokens : 4096,
            family: 'claude',
            toolCalls: true,
            vision: $vision,
            thinking: $thinking,
            metadataSource: 'provider-api',
            fieldSources: [
                'contextWindow' => $hasContextWindow ? 'provider-api' : 'default',
                'maxTokens' => $hasMaxTokens ? 'provider-api' : 'default',
            ],
            extras: array_filter(
                [
                    'capabilities' => $model['capabilities'] ?? null,
                    'lifecycle' => $model['lifecycle'] ?? null,
                    'created_at' => $model['created_at'] ?? null,
                ],
                static fn(mixed $value): bool => $value !== null,
            ),
        );
    }

    public function isAvailable(): bool
    {
        if ($this->apiKey === '') {
            return false;
        }

        try {
            $this->httpClient->request('GET', "{$this->baseUrl}/models", [...$this->requestLimits->apply([
                'headers' => $this->headers(),
            ]), 'timeout' => 5.0]);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->debug('Anthropic availability check failed: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }

    protected function formatTools(array $tools): array
    {
        return array_map(function (ToolInterface $tool) {
            $schema = $tool->toFunctionSchema();

            return [
                'name' => $schema['function']['name'],
                'description' => $schema['function']['description'],
                'input_schema' => $schema['function']['parameters'],
            ];
        }, $tools);
    }

    protected function formatMessages(array $messages): array
    {
        return array_map(fn(MessageInterface $msg) => $msg->toArray(), $messages);
    }

    /**
     * @param MessageInterface[] $messages
     * @return array{0: string, 1: array<array<string, mixed>>}
     */
    private function extractSystemAndMessages(array $messages): array
    {
        $systemPrompt = '';
        $formatted = [];

        foreach (array_values($messages) as $position => $message) {
            if ($message->role() === Role::System) {
                $content = $message->content();
                $systemPrompt = (is_string($content) ? $content : (json_encode($content) ?: ''));
                continue;
            }

            // An empty assistant turn (the agent's nudge and refusal paths add
            // them) is rejected by Anthropic; dropping it lets the user turns
            // around it merge below.
            if ($this->isEmptyAssistantTurn($message)) {
                continue;
            }

            $formatted[] = $this->formatAnthropicMessage($message, $position);
        }

        // Merge consecutive same-role messages (required by Anthropic).
        // Consecutive tool_result user messages must be combined into a single
        // user message with multiple content blocks. Handle mixed content types
        // (string + array) by normalizing strings to text content blocks.
        $merged = [];
        foreach ($formatted as $msg) {
            $last = end($merged);
            $lastKey = array_key_last($merged);
            if ($last !== false && $lastKey !== null && $last['role'] === $msg['role']) {
                $lastContent = $this->normalizeContent($last['content']);
                $msgContent = $this->normalizeContent($msg['content']);
                $merged[$lastKey]['content'] = [...$lastContent, ...$msgContent];
            } else {
                $merged[] = $msg;
            }
        }

        return [$systemPrompt, $merged];
    }

    private function isEmptyAssistantTurn(MessageInterface $message): bool
    {
        return $message->role() === Role::Assistant
            && in_array($message->content(), ['', []], true)
            && $message->toolCalls() === []
            && (!$message instanceof AssistantMessage || $message->contentBlocks() === []);
    }

    /**
     * @param int $position The message's position in the list, for a stable fallback tool_use_id
     * @return array<string, mixed>
     */
    private function formatAnthropicMessage(MessageInterface $message, int $position): array
    {
        // Blocks from this run (thinking with its signature) go back unchanged.
        if ($message instanceof AssistantMessage && $message->contentBlocks() !== []) {
            return ['role' => 'assistant', 'content' => $message->contentBlocks()];
        }

        $role = match ($message->role()) {
            Role::User => 'user',
            Role::Assistant => 'assistant',
            Role::Tool => 'user',
            default => 'user',
        };

        // Tool result messages → user message with tool_result content block
        if ($message->role() === Role::Tool) {
            $toolCallId = $message->toolCallId();

            // Anthropic requires a non-null tool_use_id. Derive a fallback
            // for replayed conversations where the ID was not persisted; it is
            // the same on every request, so the request prefix stays stable.
            if ($toolCallId === null || $toolCallId === '') {
                $toolCallId = 'toolu_' . substr(hash('sha256', $position . "\0" . json_encode($message->content())), 0, 24);
            }

            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolCallId,
                        'content' => $message->content(),
                    ],
                ],
            ];
        }

        // Assistant messages with tool calls → content blocks with tool_use
        if ($message->role() === Role::Assistant && !empty($message->toolCalls())) {
            $content = [];
            $text = $message->content();
            if (is_string($text) && $text !== '') {
                $content[] = ['type' => 'text', 'text' => $text];
            }
            foreach ($message->toolCalls() as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => !empty($toolCall->arguments) ? $toolCall->arguments : (object) [],
                ];
            }

            return [
                'role' => 'assistant',
                'content' => $content,
            ];
        }

        return [
            'role' => $role,
            'content' => $this->convertContentForAnthropic($message->content()),
        ];
    }

    /**
     * Convert message content to Anthropic-compatible format.
     *
     * Transforms OpenAI-format image_url blocks (data URI with base64) into
     * Anthropic's image source format. Text blocks and plain strings pass
     * through unchanged.
     *
     * @param string|array<array<string, mixed>> $content
     * @return string|array<array<string, mixed>>
     */
    private function convertContentForAnthropic(string|array $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }

        $converted = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'image_url' && isset($block['image_url']['url'])) {
                $url = $block['image_url']['url'];

                // Parse data URI: data:{media_type};base64,{data}
                if (preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches)) {
                    $converted[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $matches[1],
                            'data' => $matches[2],
                        ],
                    ];
                } else {
                    // URL-based image (Anthropic supports url source type)
                    $converted[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => $url,
                        ],
                    ];
                }
            } else {
                // text blocks and others pass through
                $converted[] = $block;
            }
        }

        return $converted;
    }

    protected function parseResponse(array $data): Response
    {
        $content = '';
        $reasoning = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'] ?? '';
            } elseif ($block['type'] === 'thinking') {
                $reasoning .= $block['thinking'] ?? '';
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'] ?? '',
                    name: $block['name'] ?? '',
                    arguments: $block['input'] ?? [],
                );
            }
        }

        $finishReason = $this->mapStopReason($data['stop_reason'] ?? null);

        $usage = null;
        if (isset($data['usage'])) {
            $usage = new Usage(
                promptTokens: $data['usage']['input_tokens'] ?? 0,
                completionTokens: $data['usage']['output_tokens'] ?? 0,
                totalTokens: ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
            );
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $data['model'] ?? $this->model,
            usage: $usage,
            reasoning: $reasoning,
        );
    }

    /**
     * Normalize message content to an array of content blocks.
     *
     * Anthropic requires content blocks when merging consecutive same-role
     * messages. Plain string content is converted to a text block.
     *
     * @param string|array<array<string, mixed>> $content
     * @return array<array<string, mixed>>
     */
    private function normalizeContent(string|array $content): array
    {
        if (is_string($content)) {
            return $content !== '' ? [['type' => 'text', 'text' => $content]] : [];
        }

        return $content;
    }
}
