<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\StructuredStreaming;
use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\CliProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaServerProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StructuredDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\StructuredOutput;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Support\Http\ScriptedStreamClient;

// structuredEvents() sends the request structured() builds, through the provider's streamEvents(),
// and yields the document as StructuredDelta fragments, reasoning and ticks, then one Finish.

const SS_FIRST = '{"answer":';
const SS_SECOND = '"hi"}';
const SS_BARE = '{"type":"object","properties":{"answer":{"type":"string"}},"required":["answer"],"additionalProperties":false}';
const SS_ENVELOPE = '{"name":"answer_card","description":"One answer.","schema":' . SS_BARE . '}';
const SS_USAGE_CHAT = ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5];

/**
 * The SSE events of a streamed document, split into the events before and after the first
 * fragment so a test can put an idle gap between them.
 *
 * @return array{list<string>, list<string>}
 */
function ssStreamEvents(string $format, string $toolName): array
{
    $data = fn(array $e): string => 'data: ' . json_encode($e) . "\n\n";
    $anthropic = fn(array $e): string => 'event: ' . $e['type'] . "\n" . $data($e);
    $chat = fn(array $delta, ?string $finish = null): string => $data(['id' => 'r1', 'model' => 'm-v', 'choices' => [['delta' => $delta, 'finish_reason' => $finish]]]);
    $gemini = fn(array $parts, array $extra = []): string => $data(['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], ...$extra]], 'responseId' => 'r1', 'modelVersion' => 'm-v']);

    return match ($format) {
        'chat' => [
            [$chat(['reasoning_content' => 'think']), $chat(['content' => SS_FIRST])],
            [$chat(['content' => SS_SECOND]), $chat([], 'stop'), $data(['id' => 'r1', 'choices' => [], 'usage' => SS_USAGE_CHAT]), "data: [DONE]\n\n"],
        ],
        'responses' => [
            [
                $data(['type' => 'response.reasoning_summary_text.delta', 'delta' => 'think']),
                $data(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'c1', 'name' => $toolName, 'arguments' => '']]),
                $data(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => SS_FIRST]),
            ],
            [
                $data(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => SS_SECOND]),
                $data(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'c1', 'name' => $toolName, 'arguments' => SS_FIRST . SS_SECOND]]),
                $data(['type' => 'response.completed', 'response' => ['id' => 'r1', 'model' => 'm-v', 'status' => 'completed', 'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5]]]),
            ],
        ],
        'anthropic' => [
            [
                $anthropic(['type' => 'message_start', 'message' => ['id' => 'r1', 'model' => 'm-v', 'usage' => ['input_tokens' => 3, 'output_tokens' => 1]]]),
                $anthropic(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]),
                $anthropic(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'think']]),
                $anthropic(['type' => 'content_block_stop', 'index' => 0]),
                $anthropic(['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']]),
                $anthropic(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => SS_FIRST]]),
            ],
            [
                $anthropic(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => SS_SECOND]]),
                $anthropic(['type' => 'content_block_stop', 'index' => 1]),
                $anthropic(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]]),
                $anthropic(['type' => 'message_stop']),
            ],
        ],
        'gemini' => [
            [$gemini([['text' => 'think', 'thought' => true]]), $gemini([['text' => SS_FIRST]])],
            [$gemini([['text' => SS_SECOND]], ['finishReason' => 'STOP']) . $data(['usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'totalTokenCount' => 5]])],
        ],
    };
}

/** The blocking reply structured() reads for the same document. */
function ssBlockingBody(string $format, string $toolName): string
{
    $document = SS_FIRST . SS_SECOND;

    return (string) json_encode(match ($format) {
        'chat' => ['id' => 'r1', 'model' => 'm-v', 'choices' => [['message' => ['role' => 'assistant', 'content' => $document], 'finish_reason' => 'stop']], 'usage' => SS_USAGE_CHAT],
        'responses' => ['id' => 'r1', 'model' => 'm-v', 'status' => 'completed', 'output' => [['type' => 'function_call', 'call_id' => 'c1', 'name' => $toolName, 'arguments' => $document]], 'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5]],
        'anthropic' => ['id' => 'r1', 'model' => 'm-v', 'content' => [['type' => 'text', 'text' => $document]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 3, 'output_tokens' => 2]],
        'gemini' => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $document]]], 'finishReason' => 'STOP']], 'responseId' => 'r1', 'modelVersion' => 'm-v', 'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'totalTokenCount' => 5]],
    });
}

/**
 * A mock client that answers streamed requests with the streamed document and blocking ones
 * with the blocking reply, and records every request it served.
 */
final class SsClient
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>, options: array<string, mixed>}> */
    public array $requests = [];

    public ?ResponseInterface $lastResponse = null;

    public readonly MockHttpClient $client;

    public function __construct(string $format, string $toolName)
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options) use ($format, $toolName): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $body = is_array($body) ? $body : [];
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'options' => $options];
            $streamed = ($body['stream'] ?? false) === true || str_contains($url, 'streamGenerateContent');

            return new MockResponse(
                $streamed ? implode('', array_merge(...ssStreamEvents($format, $toolName))) : ssBlockingBody($format, $toolName),
                ['http_code' => 200],
            );
        });
    }
}

/** @param class-string $class */
function ssProvider(string $class, mixed $client): AbstractProvider
{
    return new $class(model: 'm', httpClient: $client);
}

function ssToolName(string $schema): string
{
    $decoded = json_decode($schema, true);

    return is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded['name'] : 'structured_output';
}

/**
 * @param iterable<object> $events
 * @return list<object>
 */
function ssDrain(iterable $events): array
{
    return array_values(iterator_to_array($events, false));
}

/**
 * The request body without the keys only a streamed request carries.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function ssWithoutStreamKeys(array $body): array
{
    unset($body['stream'], $body['stream_options']);

    return $body;
}

dataset('structured streaming providers', [
    'OpenAI-compatible' => [OpenAICompatibleProvider::class, 'chat'],
    'Ollama' => [OllamaProvider::class, 'chat'],
    'xAI' => [XAIProvider::class, 'chat'],
    'Mistral' => [MistralProvider::class, 'chat'],
    'llama-server' => [LlamaServerProvider::class, 'chat'],
    'OpenAI Responses' => [OpenAIResponsesProvider::class, 'responses'],
    'Anthropic' => [AnthropicProvider::class, 'anthropic'],
    'Gemini' => [GeminiProvider::class, 'gemini'],
]);

dataset('schema shapes', [
    'bare schema' => [SS_BARE],
    'named envelope' => [SS_ENVELOPE],
]);

test('the HTTP providers implement StructuredStreaming and CliProvider does not', function (string $class) {
    expect(is_subclass_of($class, StructuredStreaming::class))->toBeTrue()
        ->and(is_subclass_of(CliProvider::class, StructuredStreaming::class))->toBeFalse();
})->with('structured streaming providers');

test('structuredEvents yields reasoning, the document as StructuredDelta, then one Finish(Stop) last', function (string $class, string $format, string $schema) {
    $client = new SsClient($format, ssToolName($schema));

    $events = ssDrain(ssProvider($class, $client->client)->structuredEvents([new UserMessage('hi')], $schema));

    $finishes = array_filter($events, fn(object $e) => $e instanceof Finish);
    $document = implode('', array_map(fn(StructuredDelta $e) => $e->delta, array_filter($events, fn(object $e) => $e instanceof StructuredDelta)));

    expect(array_map(fn(object $e) => $e::class, $events))->toBe([ReasoningDelta::class, StructuredDelta::class, StructuredDelta::class, Finish::class])
        ->and($events[0]->delta)->toBe('think')
        ->and($finishes)->toHaveCount(1)
        ->and($events[3]->reason)->toBe(ProviderFinishReason::Stop)
        ->and(json_decode($document, true))->toBe(['answer' => 'hi'])
        ->and($client->requests)->toHaveCount(1);
})->with('structured streaming providers')->with('schema shapes');

test('structuredEvents sends the request structured() sends, streamed, and yields the document it returns', function (string $class, string $format, string $schema) {
    $client = new SsClient($format, ssToolName($schema));
    $provider = ssProvider($class, $client->client);
    $options = ['temperature' => 0.2];

    $blocking = $provider->structured([new UserMessage('hi')], $schema, $options);
    $events = ssDrain($provider->structuredEvents([new UserMessage('hi')], $schema, $options));
    [$blockingRequest, $streamRequest] = $client->requests;
    $document = implode('', array_map(fn(StructuredDelta $e) => $e->delta, array_filter($events, fn(object $e) => $e instanceof StructuredDelta)));

    expect(ssWithoutStreamKeys($streamRequest['body']))->toBe(ssWithoutStreamKeys($blockingRequest['body']))
        ->and($streamRequest['url'])->toBe($format === 'gemini'
            ? str_replace(':generateContent', ':streamGenerateContent?alt=sse', $blockingRequest['url'])
            : $blockingRequest['url'])
        ->and($streamRequest['body']['stream'] ?? ($format === 'gemini' ? true : null))->toBeTrue()
        ->and(json_decode($document, true))->toBe(StructuredOutput::decode($blocking));
})->with('structured streaming providers')->with('schema shapes');

test('structuredEvents reports usage with operation structured_stream', function (string $class, string $format, string $schema) {
    $client = new SsClient($format, ssToolName($schema));
    $listener = new class implements UsageListener {
        /** @var list<UsageReport> */
        public array $reports = [];

        public function onUsage(UsageReport $report): void
        {
            $this->reports[] = $report;
        }
    };

    ssDrain(ssProvider($class, $client->client)->withUsageListener($listener)->structuredEvents([new UserMessage('hi')], $schema));

    expect($listener->reports)->toHaveCount(1)
        ->and($listener->reports[0]->operation)->toBe('structured_stream')
        ->and($listener->reports[0]->failed)->toBeFalse()
        ->and($listener->reports[0]->usage?->totalTokens)->toBe(5)
        ->and($client->requests[0]['body'])->not->toHaveKey(UsageReport::OPERATION_OPTION);
})->with('structured streaming providers')->with('schema shapes');

test('abandoning structuredEvents cancels the HTTP request', function (string $class, string $format, string $schema) {
    $client = new MockHttpClient(function () use ($format, $schema): MockResponse {
        return new MockResponse(implode('', array_merge(...ssStreamEvents($format, ssToolName($schema)))), ['http_code' => 200]);
    });
    $capturing = new class ($client) implements \Symfony\Contracts\HttpClient\HttpClientInterface {
        use \Symfony\Component\HttpClient\DecoratorTrait;

        public ?ResponseInterface $response = null;

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->response = $this->client->request($method, $url, $options);
        }
    };

    $events = ssProvider($class, $capturing)->structuredEvents([new UserMessage('hi')], $schema);
    $iterator = (fn(iterable $i): \Generator => yield from $i)($events);
    expect($iterator->current())->toBeInstanceOf(ReasoningDelta::class)
        ->and($capturing->response?->getInfo('canceled'))->not->toBeTrue();

    unset($iterator, $events);

    expect($capturing->response?->getInfo('canceled'))->toBeTrue();
})->with('structured streaming providers')->with('schema shapes');

test('structuredEvents passes Ticks through during an idle gap', function (string $class, string $format, string $schema) {
    [$before, $after] = ssStreamEvents($format, ssToolName($schema));
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, implode('', $before)), ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, implode('', $after)), new LastChunk()],
    ]);
    $provider = ssProvider($class, $client)->withRequestLimits(new RequestLimits(idleTimeout: 5.0, tickInterval: 0.05));

    $events = ssDrain($provider->structuredEvents([new UserMessage('hi')], $schema));

    expect(array_map(fn(object $e) => $e::class, $events))->toBe([ReasoningDelta::class, StructuredDelta::class, Tick::class, StructuredDelta::class, Finish::class]);
})->with('structured streaming providers')->with('schema shapes');

test('no option supplies the URL or replaces the injected client', function (string $class, string $format, string $schema) {
    $client = new SsClient($format, ssToolName($schema));
    $provider = ssProvider($class, $client->client);
    $other = new MockHttpClient(fn() => throw new LogicException('a client from options was used'));

    ssDrain($provider->structuredEvents([new UserMessage('hi')], $schema));
    ssDrain($provider->structuredEvents([new UserMessage('hi')], $schema, [
        'base_uri' => 'https://attacker.example/',
        'url' => 'https://attacker.example/v1',
        'baseUrl' => 'https://attacker.example/v1',
        'base_url' => 'https://attacker.example/v1',
        'endpoint' => 'https://attacker.example/v1',
        'http_client' => $other,
        'httpClient' => $other,
        'client' => $other,
        'headers' => ['Host' => 'attacker.example'],
        'proxy' => 'http://attacker.example:8080',
    ]));
    [$plain, $hostile] = $client->requests;

    expect($hostile['url'])->toBe($plain['url'])
        ->and($hostile['url'])->not->toContain('attacker')
        ->and($hostile['options']['base_uri'] ?? null)->toBe($plain['options']['base_uri'] ?? null)
        ->and($hostile['options']['proxy'] ?? null)->toBe($plain['options']['proxy'] ?? null)
        ->and($hostile['options']['normalized_headers']['host'] ?? null)->toBe($plain['options']['normalized_headers']['host'] ?? null);
})->with('structured streaming providers')->with(['bare schema' => [SS_BARE]]);

test('the Responses stream drops text and other calls, and needs the forced call to end', function () {
    $data = fn(array $e): string => 'data: ' . json_encode($e) . "\n\n";
    $body = $data(['type' => 'response.output_text.delta', 'delta' => 'prose'])
        . $data(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'c0', 'name' => 'other', 'arguments' => '']])
        . $data(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"x":1}'])
        . $data(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => 'c0', 'name' => 'other', 'arguments' => '{"x":1}']])
        . $data(['type' => 'response.completed', 'response' => ['model' => 'm-v', 'status' => 'completed']]);
    $client = new MockHttpClient([new MockResponse($body, ['http_code' => 200])]);

    $events = ssDrain((new OpenAIResponsesProvider(model: 'm', httpClient: $client))->structuredEvents([new UserMessage('hi')], SS_BARE));

    expect(array_map(fn(object $e) => $e::class, $events))->toBe([Finish::class])
        ->and($events[0]->reason)->toBe(ProviderFinishReason::Error);
});

test('Anthropic streams the instructed JSON for a schema native structured outputs cannot take', function (string $schema) {
    $client = new SsClient('anthropic', 'structured_output');
    $provider = new AnthropicProvider(model: 'm', httpClient: $client->client);

    $blocking = $provider->structured([new UserMessage('hi')], $schema);
    $events = ssDrain($provider->structuredEvents([new UserMessage('hi')], $schema));
    [$blockingRequest, $streamRequest] = $client->requests;
    $last = $streamRequest['body']['messages'][array_key_last($streamRequest['body']['messages'])];

    expect($streamRequest['body'])->not->toHaveKey('output_config')
        ->and(ssWithoutStreamKeys($streamRequest['body']))->toBe(ssWithoutStreamKeys($blockingRequest['body']))
        ->and(json_encode($last))->toContain('Respond with only a JSON value')
        ->and($blocking)->toBe(['answer' => 'hi'])
        ->and(array_map(fn(object $e) => $e::class, $events))->toBe([ReasoningDelta::class, StructuredDelta::class, StructuredDelta::class, Finish::class]);
})->with([
    'bare open schema' => ['{"type":"object","description":"anything"}'],
    'named open envelope' => ['{"name":"anything","schema":{"type":"object","description":"anything"}}'],
]);

test('a schema that is not JSON streams the unconstrained reply, as structured() sends it', function (string $class, string $format) {
    $client = new SsClient($format, 'structured_output');
    $provider = ssProvider($class, $client->client);

    $provider->structured([new UserMessage('hi')], 'not json');
    $events = ssDrain($provider->structuredEvents([new UserMessage('hi')], 'not json'));
    [$blockingRequest, $streamRequest] = $client->requests;

    expect(ssWithoutStreamKeys($streamRequest['body']))->toBe(ssWithoutStreamKeys($blockingRequest['body']))
        ->and($streamRequest['body'])->not->toHaveKey('response_format')
        ->and($events[array_key_last($events)])->toBeInstanceOf(Finish::class);
})->with([
    'OpenAI-compatible' => [OpenAICompatibleProvider::class, 'chat'],
    'Anthropic' => [AnthropicProvider::class, 'anthropic'],
    'Gemini' => [GeminiProvider::class, 'gemini'],
]);
