<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Schema\JsonSchemaRepair;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
final class GeminiProvider extends AbstractProvider
{
    /** JSON Schema keywords unsupported by Gemini. */
    private const UNSUPPORTED_KEYWORDS = [
        'additionalProperties',
        '$schema',
        '$ref',
        '$defs',
        'definitions',
        'patternProperties',
        'default',
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
        'propertyNames',
        'dependentSchemas',
        'dependentRequired',
    ];

    /**
     * Test seam for the image guard's DNS lookup: host name to its addresses. Null uses
     * lookupHostAddresses(). Private and set only by reflection in tests; not public API.
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
        $payload = $this->buildPayload($messages, $tools, $options);

        $response = $this->httpClient->request(
            'POST',
            "{$this->baseUrl}/models/{$this->model}:generateContent",
            $this->requestLimits->apply([
                'headers' => $this->headers(),
                'json' => $payload,
            ]),
        );

        return $this->parseResponse($this->decodeResponse($response));
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $payload = $this->buildPayload($messages, $tools, $options);

        $response = $this->httpClient->request(
            'POST',
            "{$this->baseUrl}/models/{$this->model}:streamGenerateContent?alt=sse",
            $this->requestLimits->apply([
                'headers' => $this->headers(),
                'json' => $payload,
            ]),
        );

        $parser = new SseStreamParser($this->httpClient, $response);
        $toolCallCounter = 0;

        foreach ($parser->events() as $json) {
            $candidate = $json['candidates'][0] ?? [];
            $parts = $candidate['content']['parts'] ?? [];
            $finishReason = $this->mapGeminiFinishReason($candidate['finishReason'] ?? null);

            $text = '';
            $reasoning = '';
            $toolCalls = [];

            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    // Thought parts (thinkingConfig.includeThoughts = true) have thought: true
                    if (($part['thought'] ?? false) === true) {
                        $reasoning .= $part['text'];
                    } else {
                        $text .= $part['text'];
                    }
                } elseif (isset($part['functionCall'])) {
                    $name = $part['functionCall']['name'];
                    $metadata = [];
                    if (isset($part['thoughtSignature'])) {
                        $metadata['thoughtSignature'] = $part['thoughtSignature'];
                    }
                    $toolCalls[] = new ToolCall(
                        id: sprintf('g%08x', $toolCallCounter++),
                        name: $name,
                        arguments: $part['functionCall']['args'] ?? [],
                        metadata: $metadata,
                    );
                }
            }

            // Yield reasoning separately so the agent can emit agent.reasoning events
            if ($reasoning !== '') {
                yield new Response(
                    content: '',
                    finishReason: ProviderFinishReason::Stop,
                    toolCalls: [],
                    model: $this->model,
                    reasoning: $reasoning,
                );
            }

            if (!empty($toolCalls)) {
                yield new Response(
                    content: $text,
                    finishReason: ProviderFinishReason::ToolUse,
                    toolCalls: $toolCalls,
                    model: $this->model,
                    usage: $this->parseUsageMetadata($json),
                );
                continue;
            }

            if ($text !== '' || $finishReason !== ProviderFinishReason::Stop) {
                yield new Response(
                    content: $text,
                    finishReason: $finishReason,
                    toolCalls: [],
                    model: $this->model,
                    usage: $this->parseUsageMetadata($json),
                );
            }
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        $schemaData = json_decode($schema, true);
        if ($schemaData === null) {
            return $this->chat($messages, [], $options);
        }

        // Use generationConfig with response_mime_type for structured output
        $responseSchema = $schemaData['schema'] ?? $schemaData['parameters'] ?? $schemaData;

        if (!is_array($responseSchema)) {
            return $this->chat($messages, [], $options);
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

        return $this->chat($messages, [], $options);
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

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
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

        // Merge any remaining options at top level
        return array_merge($payload, $options);
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
     * Each hop must be http(s) and its host must resolve only to global addresses
     * (FILTER_FLAG_GLOBAL_RANGE, plus the IPv6 DENIED_IPV6_RANGES), so loopback, private,
     * link-local, CGNAT, NAT64, site-local, multicast and other reserved ranges are never
     * requested. The checked address is pinned with the
     * `resolve` option so the connection goes to that address. The client follows no
     * redirects; this method follows up to IMAGE_MAX_REDIRECTS 3xx hops itself and checks
     * each Location the same way. Every hop is limited to 10s idle, 30s total and 20 MiB.
     *
     * The fetch goes through $imageHttpClient, or a library-owned HttpClient::create()
     * when none was given, never through the provider's API client: an injected host
     * client may ignore `resolve`, `max_redirects` or `on_progress`, and this guard needs
     * all three.
     *
     * A refused hop, a 3xx without Location, a fourth redirect, a non-2xx status, a
     * content type that is not image/*, an empty body or any exception returns null.
     *
     * @return array{inlineData: array{mimeType: string, data: string}}|null
     */
    private function downloadImageForInlineData(string $url): ?array
    {
        $limits = new RequestLimits(idleTimeout: 10.0, maxDuration: 30.0, maxResponseBytes: 20_971_520);
        $client = $this->imageHttpClient ?? HttpClient::create();

        try {
            for ($hop = 0; $hop <= self::IMAGE_MAX_REDIRECTS; $hop++) {
                $pin = $this->publicHostPin($url);
                if ($pin === null) {
                    $this->logger?->debug('Refused image URL: {url}', ['url' => $url]);

                    return null;
                }

                $response = $client->request('GET', $url, $limits->apply([
                    'headers' => ['Accept' => 'image/*'],
                    'resolve' => $pin,
                ]));

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 300 && $statusCode < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? '';
                    $response->cancel();
                    if ($location === '') {
                        return null;
                    }
                    $url = $this->resolveRedirectUrl($url, $location);
                    continue;
                }

                if ($statusCode < 200 || $statusCode >= 300) {
                    return null;
                }

                $contentType = $response->getHeaders()['content-type'][0] ?? '';
                $mime = strtolower(trim(explode(';', $contentType)[0]));
                if (!str_starts_with($mime, 'image/')) {
                    return null;
                }

                $body = $response->getContent();
                if ($body === '') {
                    return null;
                }

                return [
                    'inlineData' => [
                        'mimeType' => $mime,
                        'data' => base64_encode($body),
                    ],
                ];
            }

            $this->logger?->debug('Refused image URL after {max} redirects', ['max' => self::IMAGE_MAX_REDIRECTS]);

            return null;
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to download image from URL: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Returns the `resolve` option `[host => ip]` for an http(s) URL whose host resolves
     * only to global addresses (an empty array when the host is an IP literal), or null when the scheme is not http(s), the host does not
     * resolve, or any resolved address is not global.
     *
     * @return array<string, string>|null
     */
    private function publicHostPin(string $url): ?array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);
        if (($scheme !== 'http' && $scheme !== 'https') || !is_string($host) || $host === '') {
            return null;
        }

        $addresses = $this->resolveHostAddresses(trim($host, '[]'));
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false || self::inDeniedIpv6Range($ip)) {
                return null;
            }
        }

        // An IP literal has no DNS to rebind, and curl rejects a bracketed IPv6 resolve key.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return [$host => $addresses[0]];
    }

    /**
     * IPv6 ranges FILTER_FLAG_GLOBAL_RANGE accepts that can still reach a private or
     * non-unicast address: NAT64 (/96 well-known, /48 local-use), deprecated site-local,
     * multicast and the reserved ::/8 block. ::/8 holds IPv4-compatible (::/96, e.g.
     * ::7f00:1), IPv4-translated (::ffff:0:0:0/96, RFC 2765) and IPv4-mapped
     * (::ffff:0:0/96, which PHP's filter already refuses) addresses.
     */
    private const array DENIED_IPV6_RANGES = [
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['fec0::', 10],
        ['ff00::', 8],
        ['::', 8],
    ];

    /** True when $ip is an IPv6 address inside one of DENIED_IPV6_RANGES. */
    private static function inDeniedIpv6Range(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        foreach (self::DENIED_IPV6_RANGES as [$prefix, $bits]) {
            $range = (string) inet_pton($prefix);
            $bytes = intdiv($bits, 8);
            if (strncmp($packed, $range, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            $mask = $rest === 0 ? 0 : (0xFF << (8 - $rest)) & 0xFF;
            if ($rest === 0 || (ord($packed[$bytes]) & $mask) === (ord($range[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the host itself when it is an IP literal, otherwise the addresses
     * $hostResolver (by default lookupHostAddresses()) gives for it. Empty when nothing
     * resolves.
     *
     * @return list<string>
     */
    private function resolveHostAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return ($this->hostResolver ?? $this->lookupHostAddresses(...))($host);
    }

    /**
     * The host name's A records (gethostbynamel) followed by its AAAA records
     * (dns_get_record). Empty when nothing resolves.
     *
     * @return list<string>
     */
    private function lookupHostAddresses(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /**
     * Resolves a Location header against the URL that answered with it: an absolute URL
     * is returned as is, `//host/...` takes the current scheme, `/path` takes the
     * current origin, `?query` replaces the current query, and any other value replaces
     * the last segment of the current path.
     */
    private function resolveRedirectUrl(string $current, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:)(//[^/?\#]*)([^?\#]*)#i', $current, $m) !== 1) {
            return $location;
        }
        [, $scheme, $authority, $path] = $m;

        if (str_starts_with($location, '//')) {
            return $scheme . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . $authority . $location;
        }
        if (str_starts_with($location, '?')) {
            return $scheme . $authority . $path . $location;
        }

        $directory = substr($path, 0, (int) strrpos($path, '/'));

        return $scheme . $authority . $directory . '/' . $location;
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
     * `oneOf`, `not` and `if` among them — on each node the walk reaches, and
     * whatever the keyword holds goes with it. A child node left with nothing
     * becomes `{}` in normalizeChildForGemini(). A subschema under a keyword the
     * walk neither descends into nor strips, such as `unevaluatedProperties`, is
     * passed through unchanged.
     *
     * stripKeywords acts on the node, not on its `properties` map, whose members are
     * walked one by one: a property named `not` or `oneOf` keeps its name and is
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

        return SchemaUtils::stripKeywords($schema, self::UNSUPPORTED_KEYWORDS);
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
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                // Thought parts (thinkingConfig.includeThoughts = true) have thought: true
                if (($part['thought'] ?? false) === true) {
                    $reasoning .= $part['text'];
                } else {
                    $content .= $part['text'];
                }
            } elseif (isset($part['functionCall'])) {
                $name = $part['functionCall']['name'];
                $metadata = [];
                if (isset($part['thoughtSignature'])) {
                    $metadata['thoughtSignature'] = $part['thoughtSignature'];
                }
                $toolCalls[] = new ToolCall(
                    id: sprintf('g%08x', $callIndex++),
                    name: $name,
                    arguments: $part['functionCall']['args'] ?? [],
                    metadata: $metadata,
                );
            }
        }

        $finishReason = $this->mapGeminiFinishReason($candidate['finishReason'] ?? null);

        // Override finish reason if tool calls are present
        if (!empty($toolCalls)) {
            $finishReason = ProviderFinishReason::ToolUse;
        }

        return new Response(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            model: $this->model,
            usage: $this->parseUsageMetadata($data),
            reasoning: $reasoning,
        );
    }

    /**
     * Map Gemini finish reasons to ProviderFinishReason enum.
     */
    private function mapGeminiFinishReason(?string $reason): ProviderFinishReason
    {
        return match ($reason) {
            'STOP' => ProviderFinishReason::Stop,
            'MAX_TOKENS' => ProviderFinishReason::MaxTokens,
            'SAFETY' => ProviderFinishReason::Error,
            'RECITATION' => ProviderFinishReason::Error,
            default => ProviderFinishReason::Stop,
        };
    }

    /**
     * Parse usage metadata from Gemini response.
     *
     * @param array<string, mixed> $data
     */
    private function parseUsageMetadata(array $data): ?Usage
    {
        $usage = $data['usageMetadata'] ?? null;
        if ($usage === null) {
            return null;
        }

        $prompt = $usage['promptTokenCount'] ?? 0;
        $completion = $usage['candidatesTokenCount'] ?? 0;
        $total = $usage['totalTokenCount'] ?? ($prompt + $completion);

        return new Usage(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
        );
    }
}
