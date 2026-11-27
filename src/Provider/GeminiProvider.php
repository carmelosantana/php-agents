<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\StructuredStreaming;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Image\Internal\PublicImageDownloader;
use CarmeloSantana\PHPAgents\Provider\Internal\StructuredStream;
use CarmeloSantana\PHPAgents\Provider\Internal\TokenCount;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolArguments;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Schema\JsonSchemaRepair;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Native provider for Google Gemini models.
 *
 * Uses Gemini's native REST API (not the OpenAI-compatible endpoint) for
 * full access to Gemini-specific features: inlineData images, functionDeclarations
 * tool calling, Content/Part message format, and structured output via
 * response_mime_type + response_schema.
 *
 * Endpoint pattern: POST {baseUrl}/models/{model}:generateContent
 * Auth: x-goog-api-key header (not Bearer token)
 *
 * Scope: Core chat + tools + base64 images via inlineData.
 * Future: video input, File API (fileData), grounding, code execution.
 */
final class GeminiProvider extends AbstractProvider implements StructuredStreaming
{
    /** JSON Schema keywords unsupported by Gemini. */
    private const UNSUPPORTED_KEYWORDS = [
        'additionalProperties',
        '$schema',
        '$ref',
        '$defs',
        'definitions',
        'patternProperties',
        // Gemini's Schema has no field for these (Kanboard subtask 6482). It has `anyOf`,
        // which is kept and walked instead.
        'oneOf',
        'allOf',
        'not',
        'if',
        'then',
        'else',
        'contains',
        'prefixItems',
        'additionalItems',
        'propertyNames',
        'dependentSchemas',
        'dependentRequired',
    ];

    /**
     * Test seam for the image guard's DNS lookup, passed to PublicImageDownloader: host name
     * to its addresses. Null uses real DNS. Private and set only by reflection in tests;
     * not public API.
     *
     * @var (\Closure(string): list<string>)|null
     */
    private ?\Closure $hostResolver = null;

    public function __construct(
        string $model = 'gemini-2.5-flash',
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        private readonly ?HttpClientInterface $imageHttpClient = null,
    ) {
        parent::__construct(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            logger: $logger,
        );
    }

    #[\Override]
    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $this->apiKey,
        ];
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        $operation = $this->usageOperation($options, 'chat');
        $payload = $this->buildPayload($messages, $tools, $options);
        $requestOptions = $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]);
        $data = [];
        $result = null;
        $start = $this->startClock();

        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/models/{$this->model}:generateContent", $requestOptions);
            $data = $this->decodeResponse($response);
            $result = $this->parseResponse($data);

            return $result;
        } finally {
            $this->reportGenerationUsage(
                $operation,
                $start,
                $data['responseId'] ?? null,
                $data['modelVersion'] ?? null,
                $result === null ? $this->usageAfterParseFailure($data) : $result->usage,
                $result->finishReason ?? ProviderFinishReason::Error,
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function usageAfterParseFailure(array $data): ?Usage
    {
        try {
            return $this->parseUsage($data);
        } catch (\Throwable) {
            // Usage recovery is best-effort so the original parse error can propagate.
            return null;
        }
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        $operation = $this->usageOperation($options, 'stream');
        $payload = $this->buildPayload($messages, $tools, $options);
        $requestOptions = $this->requestLimits->apply([
            'headers' => $this->headers(),
            'json' => $payload,
        ]);
        $usage = $responseId = $modelVersion = null;
        $finished = false;
        $start = $this->startClock();

        try {
            $response = $this->httpClient->request('POST', "{$this->baseUrl}/models/{$this->model}:streamGenerateContent?alt=sse", $requestOptions);
            $events = $this->readStreamEvents($response, $usage, $responseId, $modelVersion);
            foreach (self::cancelOnAbandon($response, $events) as $event) {
                if ($event instanceof Finish) {
                    $finished = true;
                    $this->reportGenerationUsage($operation, $start, $responseId, $modelVersion, $event->usage, $event->reason);
                }

                yield $event;
            }
        } finally {
            if (!$finished) {
                $this->reportGenerationUsage($operation, $start, $responseId, $modelVersion, $usage, ProviderFinishReason::Error);
            }
        }
    }

    private function reportGenerationUsage(
        string $operation,
        int $start,
        mixed $responseId,
        mixed $modelVersion,
        ?Usage $usage,
        ProviderFinishReason $reason,
    ): void {
        $failed = self::finishedFailed($reason);
        $requestedModel = $this->getModel();
        $this->reportUsage(new UsageReport(
            operation: $operation,
            provider: 'GeminiProvider',
            requestedModel: $requestedModel,
            model: !$failed && is_string($modelVersion) && $modelVersion !== '' ? $modelVersion : $requestedModel,
            usage: $usage,
            requestId: null,
            responseId: is_string($responseId) ? $responseId : null,
            finishReason: $reason,
            durationMs: $this->elapsedMs($start),
            failed: $failed,
        ));
    }

    /**
     * The events of the response streamEvents() requested; cancelOnAbandon() closes it.
     *
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    private function readStreamEvents(
        ResponseInterface $response,
        ?Usage &$usage,
        ?string &$responseId,
        ?string &$modelVersion,
    ): \Generator {
        // Each chunk's parts, in order:
        //   text                → TextDelta (ReasoningDelta when `thought: true`)
        //   functionCall        → ToolInputStart, one ToolInputDelta with the whole JSON
        //                         arguments, ToolInputEnd (thoughtSignature in its metadata)
        // A chunk whose finish reason is ContentFilter, Error or MaxTokens emits no tool events.
        // The first chunk with a candidate finishReason or a promptFeedback.blockReason ends the
        // answer: later parts are ignored, but later usageMetadata still replaces the usage, so
        // the single Finish is yielded at EOF. Gemini has no tool-use finish reason: a Stop
        // finish after any functionCall part is ToolUse, and Error when a call had no name or its
        // arguments were not a JSON object. A stream that ends without a finish yields Finish(Error).
        $callCount = 0;
        $invalidArguments = false;
        /** @var array{reason: ProviderFinishReason, raw: string}|null $finish */
        $finish = null;

        $parser = new SseStreamParser(
            $this->httpClient,
            $response,
            SseStreamParser::DEFAULT_MAX_LINE_BYTES,
            $this->requestLimits->tickInterval,
            $this->requestLimits->idleTimeout,
        );

        foreach ($parser->events() as $json) {
            if ($json instanceof Tick) {
                yield $json;

                continue;
            }

            if (is_string($json['responseId'] ?? null)) {
                $responseId = $json['responseId'];
            }
            if (is_string($json['modelVersion'] ?? null)) {
                $modelVersion = $json['modelVersion'];
            }
            $usage = $this->parseUsage($json) ?? $usage;

            if ($finish !== null) {
                continue;
            }

            $candidate = $json['candidates'][0] ?? [];
            $parts = $candidate['content']['parts'] ?? [];
            $reason = $this->finishReasonOf($json);
            $dropsToolCalls = $this->dropsToolCalls($reason);

            foreach ($parts as $part) {
                if (is_string($part['text'] ?? null)) {
                    if ($part['text'] === '') {
                        continue;
                    }
                    yield ($part['thought'] ?? false) === true
                        ? new ReasoningDelta($part['text'])
                        : new TextDelta($part['text']);
                } elseif (isset($part['functionCall']) && !$dropsToolCalls) {
                    $index = $callCount++;
                    $id = sprintf('g%08x', $index);
                    $name = is_string($part['functionCall']['name'] ?? null) ? $part['functionCall']['name'] : '';
                    $argumentsJson = ToolArguments::toJson($part['functionCall']['args'] ?? []);

                    yield new ToolInputStart($index, $id, $name);

                    if ($name === '') {
                        $this->logger?->debug('Gemini functionCall has no name; the call is dropped');
                        $invalidArguments = true;

                        continue;
                    }

                    // Checked before the delta, so arguments over a cap are never yielded.
                    $arguments = ToolArguments::decode($argumentsJson, $this->requestLimits);
                    if ($arguments === null) {
                        $this->logger?->debug('Tool {tool} arguments ({length} bytes) are not a JSON object; the call is dropped', [
                            'tool' => $name,
                            'length' => strlen($argumentsJson),
                        ]);
                        $invalidArguments = true;

                        continue;
                    }

                    yield new ToolInputDelta($index, $id, $argumentsJson);
                    $metadata = isset($part['thoughtSignature']) ? ['thoughtSignature' => $part['thoughtSignature']] : [];
                    yield new ToolInputEnd($index, $id, $name, $arguments, $metadata);
                }
            }

            $rawFinish = $candidate['finishReason'] ?? null;
            $blockReason = $json['promptFeedback']['blockReason'] ?? null;
            if ($rawFinish === null && $blockReason === null) {
                continue;
            }

            if ($reason === ProviderFinishReason::Stop && $callCount > 0) {
                $reason = $invalidArguments ? ProviderFinishReason::Error : ProviderFinishReason::ToolUse;
            }

            $finish = [
                'reason' => $reason,
                'raw' => match (true) {
                    is_string($rawFinish) => $rawFinish,
                    is_string($blockReason) => $blockReason,
                    default => '',
                },
            ];
        }

        yield $finish === null
            ? new Finish(ProviderFinishReason::Error, usage: $usage, model: $this->model)
            : new Finish($finish['reason'], $finish['raw'], $usage, $this->model);
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $options[UsageReport::OPERATION_OPTION] = 'structured';

        return $this->chat($messages, [], $this->structuredOptions($schema, $options));
    }

    public function structuredEvents(array $messages, string $schema, array $options = []): iterable
    {
        $options[UsageReport::OPERATION_OPTION] = 'structured_stream';

        return StructuredStream::fromText($this->streamEvents($messages, [], $this->structuredOptions($schema, $options)));
    }

    /**
     * The options structured() and structuredEvents() send: $options with a JSON responseSchema
     * in generationConfig, or $options unchanged when $schema is not JSON or names no schema.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function structuredOptions(string $schema, array $options): array
    {
        $schemaData = json_decode($schema, true);
        if ($schemaData === null) {
            return $options;
        }

        // Use generationConfig with response_mime_type for structured output
        $responseSchema = $schemaData['schema'] ?? $schemaData['parameters'] ?? $schemaData;

        if (!is_array($responseSchema)) {
            return $options;
        }

        // responseSchema is the same Gemini Schema a function declaration's parameters are,
        // and arrives from outside with the same shapes: a `type` that is an array, a
        // lower-case type on a nested node, and, since json_decode() above returns arrays, an
        // empty or "0", "1", … keyed `properties` map that would encode as a JSON list.
        // JsonSchemaRepair::repair() turns those maps back into objects, and
        // normalizeSchemaForGemini() then rewrites the schema as formatTools() does a tool's
        // parameters. A type array that does not collapse to a single non-null type comes
        // back with no `type` at all, so the OBJECT default is applied after the walk, not
        // before it.
        unset($responseSchema['name'], $responseSchema['description']);
        $responseSchema = $this->normalizeSchemaForGemini(JsonSchemaRepair::repair($responseSchema));
        $responseSchema['type'] ??= 'OBJECT';

        $options['generationConfig'] = array_merge(
            $options['generationConfig'] ?? [],
            [
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        );

        return $options;
    }

    /**
     * List available models from the Gemini API.
     *
     * @return ModelDefinition[]
     */
    public function models(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/models", [...$this->requestLimits->apply([
                'headers' => $this->headers(),
            ]), 'timeout' => 10.0]);

            $data = $this->decodeResponse($response);
            $models = [];

            foreach ($data['models'] ?? [] as $model) {
                // Only include models that support generateContent
                $methods = $model['supportedGenerationMethods'] ?? [];
                if (!in_array('generateContent', $methods, true)) {
                    continue;
                }

                $id = $model['name'] ?? '';
                // Strip "models/" prefix: "models/gemini-2.5-flash" → "gemini-2.5-flash"
                if (str_starts_with($id, 'models/')) {
                    $id = substr($id, 7);
                }

                $models[] = new ModelDefinition(
                    id: $id,
                    name: $model['displayName'] ?? $id,
                    provider: 'gemini',
                    contextWindow: $model['inputTokenLimit'] ?? 1048576,
                    maxTokens: $model['outputTokenLimit'] ?? 8192,
                    family: 'gemini',
                    metadataSource: 'provider-api',
                    fieldSources: [
                        'contextWindow' => 'provider-api',
                        'maxTokens' => 'provider-api',
                    ],
                );
            }

            return $models;
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to fetch Gemini models: {error}', ['error' => $e->getMessage()]);

            return [];
        }
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
            $this->logger?->debug('Gemini availability check failed: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Build the request payload for Gemini's generateContent endpoint.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $tools, array $options): array
    {
        [$systemInstruction, $contents] = $this->extractSystemAndContents($messages);

        $payload = ['contents' => $contents];

        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        // The conversation's system message and the $tools argument are the
        // provider's: options cannot replace them, in either JSON spelling.
        foreach ($messages as $message) {
            if ($message->role() === Role::System) {
                unset($options['systemInstruction'], $options['system_instruction']);
            }
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
            unset($options['tools']);
        }

        // Merge generationConfig from options
        if (isset($options['generationConfig'])) {
            $payload['generationConfig'] = $options['generationConfig'];
            unset($options['generationConfig']);
        }

        // Pass through temperature, maxOutputTokens, etc. into generationConfig
        $genConfigKeys = ['temperature', 'topP', 'topK', 'maxOutputTokens', 'stopSequences'];
        foreach ($genConfigKeys as $key) {
            if (isset($options[$key])) {
                $payload['generationConfig'][$key] = $options[$key];
                unset($options[$key]);
            }
        }

        // Merge any remaining options at top level; the conversation stays ours.
        return [...$payload, ...$options, 'contents' => $contents];
    }

    /**
     * Extract system instruction and convert messages to Gemini Content format.
     *
     * @param MessageInterface[] $messages
     * @return array{0: ?array<string, mixed>, 1: array<array<string, mixed>>}
     */
    private function extractSystemAndContents(array $messages): array
    {
        $systemInstruction = null;
        $contents = [];
        /** @var array<string, string> $callIdToName Maps tool call ID → function name */
        $callIdToName = [];

        foreach ($messages as $message) {
            if ($message->role() === Role::System) {
                $content = $message->content();
                $text = is_string($content) ? $content : (json_encode($content) ?: '');
                if ($text !== '') {
                    $systemInstruction = ['parts' => [['text' => $text]]];
                }
                continue;
            }

            // Track tool call IDs → function names from assistant messages
            if ($message->role() === Role::Assistant && !empty($message->toolCalls())) {
                foreach ($message->toolCalls() as $toolCall) {
                    $callIdToName[$toolCall->id] = $toolCall->name;
                }
            }

            $contents[] = $this->formatGeminiContent($message, $callIdToName);
        }

        // Merge consecutive same-role contents (Gemini requires role alternation)
        /** @var array<int, array{role: string, parts: array<array<string, mixed>>}> $merged */
        $merged = [];
        foreach ($contents as $content) {
            $last = end($merged);
            $lastKey = array_key_last($merged);
            if (
                $last !== false &&
                $lastKey !== null &&
                isset($last['role'], $content['role']) &&
                $last['role'] === $content['role']
            ) {
                $merged[$lastKey]['parts'] = array_merge($last['parts'], $content['parts']);
            } else {
                $merged[] = $content;
            }
        }

        return [$systemInstruction, $merged];
    }

    /**
     * Convert a single MessageInterface to Gemini Content format.
     *
     * @param array<string, string> $callIdToName Maps tool call ID → function name
     * @return array{role: string, parts: array<array<string, mixed>>}
     */
    private function formatGeminiContent(MessageInterface $message, array $callIdToName = []): array
    {
        $role = match ($message->role()) {
            Role::User => 'user',
            Role::Assistant => 'model',
            Role::Tool => 'user',
            default => 'user',
        };

        // Tool result messages → functionResponse parts
        if ($message->role() === Role::Tool) {
            $toolCallId = $message->toolCallId() ?? '';
            // Gemini expects the actual function name, not the call ID
            $functionName = $callIdToName[$toolCallId] ?? $toolCallId;
            $content = $message->content();
            $responseData = is_string($content) ? ['result' => $content] : $content;

            return [
                'role' => 'user',
                'parts' => [
                    [
                        'functionResponse' => [
                            'name' => $functionName,
                            'response' => $responseData,
                        ],
                    ],
                ],
            ];
        }

        // Assistant messages with tool calls → functionCall parts
        if ($message->role() === Role::Assistant && !empty($message->toolCalls())) {
            $parts = [];
            $text = $message->content();
            if (is_string($text) && $text !== '') {
                $parts[] = ['text' => $text];
            }
            foreach ($message->toolCalls() as $toolCall) {
                $part = [
                    'functionCall' => [
                        'name' => $toolCall->name,
                        'args' => !empty($toolCall->arguments) ? $toolCall->arguments : (object) [],
                    ],
                ];
                // Gemini 3 requires thoughtSignature on functionCall parts.
                // Use real signature if available, otherwise dummy to skip validation.
                if (isset($toolCall->metadata['thoughtSignature'])) {
                    $part['thoughtSignature'] = $toolCall->metadata['thoughtSignature'];
                } else {
                    $part['thoughtSignature'] = 'skip_thought_signature_validator';
                }
                $parts[] = $part;
            }

            return ['role' => 'model', 'parts' => $parts];
        }

        // Regular messages → text and/or inlineData parts
        return [
            'role' => $role,
            'parts' => $this->convertContentToParts($message->content()),
        ];
    }

    /**
     * Convert message content to Gemini parts array.
     *
     * Handles string content, text blocks, and OpenAI-format image_url blocks
     * (converting them to Gemini's inlineData format).
     *
     * @param string|array<array<string, mixed>> $content
     * @return array<array<string, mixed>>
     */
    private function convertContentToParts(string|array $content): array
    {
        if (is_string($content)) {
            return $content !== '' ? [['text' => $content]] : [];
        }

        $parts = [];
        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $parts[] = ['text' => $block['text'] ?? ''];
            } elseif ($type === 'image_url') {
                $parts[] = $this->convertImageToPart($block);
            } else {
                // Unknown block types — try to extract text
                if (isset($block['text'])) {
                    $parts[] = ['text' => $block['text']];
                }
            }
        }

        return $parts;
    }

    /**
     * Convert an OpenAI-format image_url block to a Gemini inlineData part.
     *
     * Gemini's inlineData only accepts base64. For data URIs we extract the
     * data directly. For HTTP(S) URLs we download the image and base64-encode
     * it (Gemini has no native URL reference support — the File API is the
     * official mechanism, but downloading + inlineData works for typical sizes).
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function convertImageToPart(array $block): array
    {
        $imageData = $block['image_url'] ?? [];
        $url = is_array($imageData) ? ($imageData['url'] ?? '') : (string) $imageData;

        // Parse data URI: data:{media_type};base64,{data}
        if (preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches)) {
            return [
                'inlineData' => [
                    'mimeType' => $matches[1],
                    'data' => $matches[2],
                ],
            ];
        }

        // URL-based images — download and convert to inlineData
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $downloaded = $this->downloadImageForInlineData($url);
            if ($downloaded !== null) {
                return $downloaded;
            }

            // Fallback: include URL as text so the model at least knows an image was intended
            return [
                'text' => "[Image: could not download {$url} — provide a base64 data URI instead]",
            ];
        }

        // Unknown format — pass as text reference
        return [
            'text' => "[Image reference: {$url}]",
        ];
    }

    /** Most redirects followed while downloading an image for inlineData. */
    private const int IMAGE_MAX_REDIRECTS = 3;

    /**
     * Download an image URL and return a Gemini inlineData part.
     *
     * The fetch goes through PublicImageDownloader, which only requests public addresses
     * (each hop checked and pinned, redirects followed by hand up to IMAGE_MAX_REDIRECTS),
     * with 10s idle, 30s total and 20 MiB per hop. It uses $imageHttpClient, or a
     * library-owned HttpClient::create() when none was given, never the provider's API
     * client: an injected host client may ignore `resolve`, `max_redirects`, `no_proxy`
     * or `on_progress`, and the guard needs them all.
     *
     * Anything the downloader refuses returns null.
     *
     * @return array{inlineData: array{mimeType: string, data: string}}|null
     */
    private function downloadImageForInlineData(string $url): ?array
    {
        $downloader = new PublicImageDownloader($this->imageHttpClient, $this->logger, $this->hostResolver, self::IMAGE_MAX_REDIRECTS);
        $image = $downloader->download($url, new RequestLimits(idleTimeout: 10.0, maxDuration: 30.0, maxResponseBytes: 20_971_520));
        if ($image === null) {
            return null;
        }

        return [
            'inlineData' => [
                'mimeType' => $image['mimeType'],
                'data' => base64_encode($image['bytes']),
            ],
        ];
    }

    /**
     * Format tools for Gemini's functionDeclarations format.
     *
     * Gemini expects: [{functionDeclarations: [{name, description, parameters}]}]
     *
     * @param ToolInterface[] $tools
     * @return array<array<string, mixed>>
     */
    protected function formatTools(array $tools): array
    {
        $declarations = [];

        foreach ($tools as $tool) {
            $schema = $tool->toFunctionSchema();
            $function = $schema['function'] ?? $schema;

            $declaration = [
                'name' => $function['name'] ?? '',
                'description' => $function['description'] ?? '',
            ];

            if (isset($function['parameters'])) {
                $declaration['parameters'] = $this->normalizeSchemaForGemini(
                    $function['parameters'],
                );
            }

            $declarations[] = $declaration;
        }

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Normalize JSON Schema for Gemini compatibility, at the depths the walk below reaches.
     *
     * Gemini expects upper-case type names (STRING, OBJECT, …), a single type per
     * node with `nullable` for "or null", and none of UNSUPPORTED_KEYWORDS. The walk
     * descends through the members of `properties`, a single-schema `items` and the
     * `anyOf` branches, so a raw schema's nested nodes are normalised as well as its
     * top level. stripKeywords then unsets each UNSUPPORTED_KEYWORDS keyword — `$defs`,
     * `oneOf`, `not` and `if` among them — and stripKeywordsWithPrefix every `x-`
     * extension keyword, on each node the walk reaches, and whatever the keyword holds
     * goes with it. A child node left with nothing becomes `{}` in
     * normalizeChildForGemini(). A subschema under a keyword the
     * walk neither descends into nor strips, such as `unevaluatedProperties`, is
     * passed through unchanged.
     *
     * stripKeywords acts on the node, not on its `properties` map, whose members are
     * walked one by one: a property named `not`, `oneOf` or `x-foo` keeps its name and is
     * normalised, and a `required` list naming it is left as it is.
     *
     * JsonSchemaRepair restores an empty `{}` as a `\stdClass`, and casts to one a
     * `properties` map whose keys run "0", "1", … in order, so a subschema or a map
     * here may not be an array. A `properties` map that is a `\stdClass` is copied,
     * its array members are normalised in the copy, and the copy goes out as a
     * `\stdClass`, so it still encodes as a JSON object and the caller's object is
     * left as it was. Gemini's `items` is one Schema, so a draft-04 tuple `items`, a
     * non-empty list of subschemas, is replaced with `{}`, the schema that accepts
     * anything. Anything else in those positions that is not an array is left alone
     * rather than raising a TypeError.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function normalizeSchemaForGemini(array $schema): array
    {
        if (isset($schema['type']) && is_array($schema['type'])) {
            $types = array_values(array_filter($schema['type'], static fn(mixed $t): bool => $t !== 'null'));
            if (count($types) < count($schema['type'])) {
                $schema['nullable'] = true;
            }
            if (count($types) === 1 && is_string($types[0])) {
                $schema['type'] = $types[0];
            } else {
                unset($schema['type']);
            }
        }

        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = strtoupper($schema['type']);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = $this->normalizeChildForGemini($property);
                }
            }
        } elseif (isset($schema['properties']) && $schema['properties'] instanceof \stdClass) {
            $properties = clone $schema['properties'];
            foreach (get_object_vars($properties) as $key => $property) {
                if (is_array($property)) {
                    $properties->{$key} = $this->normalizeChildForGemini($property);
                }
            }
            $schema['properties'] = $properties;
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $schema['items'] !== [] && array_is_list($schema['items'])
                ? new \stdClass()
                : $this->normalizeChildForGemini($schema['items']);
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $index => $variant) {
                if (is_array($variant)) {
                    $schema['anyOf'][$index] = $this->normalizeChildForGemini($variant);
                }
            }
        }

        return SchemaUtils::stripKeywordsWithPrefix(
            SchemaUtils::stripKeywords($schema, self::UNSUPPORTED_KEYWORDS),
            'x-',
        );
    }

    /**
     * Normalize a subschema, keeping it an object when nothing survives.
     *
     * A node built only of UNSUPPORTED_KEYWORDS — `{"$ref": "#/$defs/x"}` from an MCP
     * server — normalizes to an empty array, which json_encode() writes as `[]`. A
     * subschema position must carry an object, so an emptied node becomes `{}`: the
     * schema that accepts anything, which is what is left once the keyword that
     * constrained it is gone.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>|\stdClass
     */
    private function normalizeChildForGemini(array $schema): array|\stdClass
    {
        $normalized = $this->normalizeSchemaForGemini($schema);

        return $normalized === [] ? new \stdClass() : $normalized;
    }

    /**
     * Format messages — delegates to extractSystemAndContents.
     *
     * @param MessageInterface[] $messages
     * @return array<array<string, mixed>>
     */
    protected function formatMessages(array $messages): array
    {
        [, $contents] = $this->extractSystemAndContents($messages);

        return $contents;
    }

    /**
     * Parse Gemini API response into a Response value object.
     *
     * @param array<string, mixed> $data
     */
    protected function parseResponse(array $data): Response
    {
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $content = '';
        $reasoning = '';
        $toolCalls = [];

        $callIndex = 0;
        $droppedCalls = false;
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                // Thought parts (thinkingConfig.includeThoughts = true) have thought: true
                if (($part['thought'] ?? false) === true) {
                    $reasoning .= $part['text'];
                } else {
                    $content .= $part['text'];
                }
            } elseif (isset($part['functionCall'])) {
                $name = is_string($part['functionCall']['name'] ?? null) ? $part['functionCall']['name'] : '';
                $metadata = [];
                if (isset($part['thoughtSignature'])) {
                    $metadata['thoughtSignature'] = $part['thoughtSignature'];
                }
                $id = sprintf('g%08x', $callIndex++);
                if ($name === '') {
                    $this->logger?->debug('Gemini functionCall has no name; the call is dropped');
                    $droppedCalls = true;

                    continue;
                }
                $arguments = ToolArguments::fromDecoded($part['functionCall']['args'] ?? null, $this->requestLimits);
                if ($arguments === null) {
                    $this->logger?->debug('Gemini tool {tool} arguments are not a JSON object; the call is dropped', ['tool' => $name]);
                    $droppedCalls = true;

                    continue;
                }

                $toolCalls[] = new ToolCall(
                    id: $id,
                    name: $name,
                    arguments: $arguments,
                    metadata: $metadata,
                );
            }
        }

        $finishReason = $this->finishReasonOf($data);

        // Tool calls make it ToolUse, unless the candidate was blocked,
        // failed or cut off at MAX_TOKENS: then no tool calls are emitted.
        // A dropped call makes what would have been ToolUse an Error, as in the stream.
        if ($this->dropsToolCalls($finishReason)) {
            $toolCalls = [];
        } elseif ($droppedCalls) {
            $finishReason = ProviderFinishReason::Error;
        } elseif (!empty($toolCalls)) {
            $finishReason = ProviderFinishReason::ToolUse;
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $this->model,
            usage: $this->parseUsage($data),
            reasoning: $reasoning,
        );
    }

    /**
     * The finish reason of a response (or stream chunk): a prompt blocked by
     * `promptFeedback.blockReason` has no candidates and is ContentFilter.
     *
     * @param array<string, mixed> $data
     */
    private function finishReasonOf(array $data): ProviderFinishReason
    {
        if (empty($data['candidates']) && isset($data['promptFeedback']['blockReason'])) {
            return ProviderFinishReason::ContentFilter;
        }

        return $this->mapGeminiFinishReason($data['candidates'][0]['finishReason'] ?? null);
    }

    private function dropsToolCalls(ProviderFinishReason $reason): bool
    {
        return $reason === ProviderFinishReason::ContentFilter
            || $reason === ProviderFinishReason::Error
            || $reason === ProviderFinishReason::MaxTokens;
    }

    /**
     * Map Gemini finish reasons to ProviderFinishReason enum.
     */
    private function mapGeminiFinishReason(?string $reason): ProviderFinishReason
    {
        return match ($reason) {
            'STOP' => ProviderFinishReason::Stop,
            'MAX_TOKENS' => ProviderFinishReason::MaxTokens,
            'SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'IMAGE_SAFETY' => ProviderFinishReason::ContentFilter,
            'MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL', 'TOO_MANY_TOOL_CALLS', 'MISSING_THOUGHT_SIGNATURE' => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
    }

    /**
     * Usage from a response or stream chunk's usageMetadata, or null when it reports
     * no counts. promptTokenCount already includes cached content; completion is
     * candidates plus thoughts.
     *
     * @param array<string, mixed> $data
     */
    private function parseUsage(array $data): ?Usage
    {
        $usage = $data['usageMetadata'] ?? null;
        if (!is_array($usage)) {
            return null;
        }

        $count = TokenCount::from(...);
        $prompt = $count($usage['promptTokenCount'] ?? null);
        $candidates = $count($usage['candidatesTokenCount'] ?? null);
        $thoughts = $count($usage['thoughtsTokenCount'] ?? null);
        $total = $count($usage['totalTokenCount'] ?? null);
        $cached = $count($usage['cachedContentTokenCount'] ?? null);

        if ($prompt === null && $candidates === null && $thoughts === null && $total === null) {
            return null;
        }

        $completion = ($candidates ?? 0) + ($thoughts ?? 0);

        return new Usage(
            promptTokens: $prompt ?? 0,
            completionTokens: $completion,
            totalTokens: $total ?? ($prompt ?? 0) + $completion,
            cacheReadTokens: $cached,
            reasoningTokens: $thoughts,
        );
    }
}
