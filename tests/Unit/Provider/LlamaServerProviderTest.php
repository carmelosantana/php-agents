<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Agent\CallbackObserver;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\LlamaServerProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Support\Http\ScriptedStreamClient;

// Wire shapes below follow llama.cpp's tools/server (b12557): server-chat.cpp
// server_chat_msg_diff_to_json_oaicompat() for deltas, server-task.cpp
// to_json_oaicompat_chat_stream() for the finish/usage chunks (timings on the last one),
// and server-context.cpp get_res_models() for /v1/models.

/**
 * @param list<array<string, mixed>> $chunks
 */
function llamaServerBody(array $chunks, bool $done = true): string
{
    $lines = array_map(fn(array $c) => 'data: ' . json_encode($c), $chunks);
    if ($done) {
        $lines[] = 'data: [DONE]';
    }

    return implode("\n\n", $lines) . "\n\n";
}

/**
 * @param array<string, mixed> $delta
 * @return array<string, mixed>
 */
function llamaServerChunk(array $delta, ?string $finishReason = null): array
{
    return [
        'choices' => [['finish_reason' => $finishReason, 'index' => 0, 'delta' => $delta]],
        'created' => 1763500000,
        'id' => 'chatcmpl-abc',
        'model' => 'qwen2.5-1.5b-instruct-q4_k_m.gguf',
        'system_fingerprint' => 'b12557-b94959a64',
        'object' => 'chat.completion.chunk',
    ];
}

/**
 * @return array<string, float|int>
 */
function llamaServerTimings(): array
{
    return [
        'cache_n' => 4, 'prompt_n' => 38, 'prompt_ms' => 30.9, 'prompt_per_token_ms' => 0.81, 'prompt_per_second' => 1229.7,
        'predicted_n' => 21, 'predicted_ms' => 661.0, 'predicted_per_token_ms' => 31.4, 'predicted_per_second' => 31.8,
    ];
}

/**
 * A MockHttpClient that records each request.
 *
 * @param list<MockResponse> $responses
 * @param list<array{method: string, url: string, options: array<string, mixed>}> $requests
 */
function llamaServerClient(array $responses, array &$requests): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
        $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
    });
}

test('LlamaServerProvider defaults to a local llama-server and sends no Authorization header without a key', function () {
    $requests = [];
    $client = llamaServerClient([new MockResponse(llamaServerBody([llamaServerChunk([], 'stop')]), ['http_code' => 200])], $requests);
    $provider = new LlamaServerProvider(model: 'local', httpClient: $client);

    iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false);

    $headers = implode("\n", $requests[0]['options']['headers'] ?? []);
    expect($requests[0]['url'])->toBe('http://localhost:8080/v1/chat/completions')
        ->and(strtolower($headers))->not->toContain('authorization');
});

test('LlamaServerProvider sends a configured --api-key as a Bearer token', function () {
    $requests = [];
    $client = llamaServerClient([new MockResponse(llamaServerBody([llamaServerChunk([], 'stop')]), ['http_code' => 200])], $requests);
    $provider = new LlamaServerProvider(model: 'local', baseUrl: 'http://127.0.0.1:18089/v1', apiKey: 'secret', httpClient: $client);

    iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false);

    expect($requests[0]['url'])->toBe('http://127.0.0.1:18089/v1/chat/completions')
        ->and($requests[0]['options']['headers'])->toContain('Authorization: Bearer secret');
});

test('LlamaServerProvider streams llama-server tool_calls as ToolInputStart/Delta/End and a ToolUse Finish with usage', function () {
    $requests = [];
    $client = llamaServerClient([new MockResponse(llamaServerBody([
        llamaServerChunk(['role' => 'assistant', 'content' => null]),
        llamaServerChunk(['reasoning_content' => 'Add them.']),
        llamaServerChunk(['tool_calls' => [['index' => 0, 'id' => 'Zx9', 'type' => 'function', 'function' => ['name' => 'add']]]]),
        llamaServerChunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"a":42,']]]]),
        llamaServerChunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"b":58}']]]]),
        llamaServerChunk([], 'tool_calls'),
        [
            'choices' => [], 'created' => 1763500000, 'id' => 'chatcmpl-abc',
            'model' => 'qwen2.5-1.5b-instruct-q4_k_m.gguf', 'object' => 'chat.completion.chunk',
            'usage' => ['completion_tokens' => 21, 'prompt_tokens' => 42, 'total_tokens' => 63, 'prompt_tokens_details' => ['cached_tokens' => 4]],
            'timings' => llamaServerTimings(),
        ],
    ]), ['http_code' => 200])], $requests);
    $provider = new LlamaServerProvider(model: 'local', httpClient: $client);

    $events = array_values(iterator_to_array($provider->streamEvents([new UserMessage('42+58?')], [
        new \CarmeloSantana\PHPAgents\Tool\Tool('add', 'Add two numbers.', [], fn(array $in) => \CarmeloSantana\PHPAgents\Tool\ToolResult::success('100')),
    ]), false));

    $payload = json_decode((string) $requests[0]['options']['body'], true);
    expect($payload['stream'])->toBeTrue()
        ->and($payload['stream_options'])->toBe(['include_usage' => true])
        ->and($payload['tools'][0]['function']['name'])->toBe('add')
        ->and($events)->toEqual([
            new ReasoningDelta('Add them.'),
            new ToolInputStart(0, 'Zx9', 'add'),
            new ToolInputDelta(0, 'Zx9', '{"a":42,'),
            new ToolInputDelta(0, 'Zx9', '"b":58}'),
            new ToolInputEnd(0, 'Zx9', 'add', ['a' => 42, 'b' => 58]),
            new Finish(ProviderFinishReason::ToolUse, 'tool_calls', new Usage(42, 21, 63, cacheReadTokens: 4), 'qwen2.5-1.5b-instruct-q4_k_m.gguf'),
        ]);
});

test('LlamaServerProvider reads the final chunk timings as usage when include_usage is turned off', function () {
    $requests = [];
    $finish = llamaServerChunk([], 'length');
    $finish['timings'] = llamaServerTimings();
    $client = llamaServerClient([new MockResponse(llamaServerBody([
        llamaServerChunk(['content' => 'Hel']),
        llamaServerChunk(['content' => 'lo']),
        $finish,
    ]), ['http_code' => 200])], $requests);
    $provider = new LlamaServerProvider(model: 'local', httpClient: $client);

    $events = array_values(iterator_to_array($provider->streamEvents([new UserMessage('hi')], [], ['stream_options' => null]), false));

    $payload = json_decode((string) $requests[0]['options']['body'], true);
    expect($payload)->not->toHaveKey('stream_options')
        ->and($events)->toEqual([
            new TextDelta('Hel'),
            new TextDelta('lo'),
            // prompt = prompt_n + cache_n, as usage_json_oaicompat() counts it.
            new Finish(ProviderFinishReason::MaxTokens, 'length', new Usage(42, 21, 63, cacheReadTokens: 4), 'qwen2.5-1.5b-instruct-q4_k_m.gguf'),
        ]);
});

test('LlamaServerProvider chat() returns llama-server reasoning_content as the response reasoning', function () {
    $requests = [];
    $client = llamaServerClient([new MockResponse(json_encode([
        'choices' => [['finish_reason' => 'stop', 'index' => 0, 'message' => ['role' => 'assistant', 'reasoning_content' => 'Two plus two.', 'content' => '4']]],
        'model' => 'qwen3-1.7b.gguf',
        'object' => 'chat.completion',
        'usage' => ['completion_tokens' => 9, 'prompt_tokens' => 12, 'total_tokens' => 21, 'prompt_tokens_details' => ['cached_tokens' => 0]],
        'timings' => llamaServerTimings(),
    ]), ['http_code' => 200])], $requests);
    $provider = new LlamaServerProvider(model: 'local', httpClient: $client);

    $response = $provider->chat([new UserMessage('2+2?')]);

    expect($response->content)->toBe('4')
        ->and($response->reasoning)->toBe('Two plus two.')
        ->and($response->usage)->toEqual(new Usage(12, 9, 21, cacheReadTokens: 0));
});

test('LlamaServerProvider models() reads the context size and modalities from llama-server /v1/models', function () {
    $requests = [];
    $client = llamaServerClient([new MockResponse(json_encode([
        'models' => [['name' => 'qwen2.5-vl.gguf', 'model' => 'qwen2.5-vl.gguf', 'capabilities' => ['completion', 'multimodal']]],
        'object' => 'list',
        'data' => [[
            'id' => 'qwen2.5-vl.gguf',
            'aliases' => [],
            'tags' => [],
            'object' => 'model',
            'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
            'created' => 1763500000,
            'owned_by' => 'llamacpp',
            'meta' => ['vocab_type' => 2, 'n_vocab' => 151936, 'n_ctx' => 8192, 'n_ctx_train' => 128000, 'n_embd' => 2048, 'n_params' => 3_000_000_000, 'size' => 1_900_000_000],
        ]],
    ]), ['http_code' => 200])], $requests);
    $provider = new LlamaServerProvider(model: 'local', httpClient: $client);

    $models = $provider->models();

    expect($requests[0]['url'])->toBe('http://localhost:8080/v1/models')
        ->and($models)->toHaveCount(1)
        ->and($models[0]->id)->toBe('qwen2.5-vl.gguf')
        ->and($models[0]->provider)->toBe('llama-server')
        ->and($models[0]->contextWindow)->toBe(8192)
        ->and($models[0]->vision)->toBeTrue();
});

test('LlamaServerProvider cancels the HTTP response when the consumer abandons the stream', function () {
    // MockHttpClient answers with a copy of the MockResponse, so keep the one request() returns.
    $client = new class (new MockHttpClient([new MockResponse(llamaServerBody([
        llamaServerChunk(['content' => 'a']),
        llamaServerChunk(['content' => 'b']),
        llamaServerChunk([], 'stop'),
    ]), ['http_code' => 200])])) implements HttpClientInterface {
        use DecoratorTrait;

        public ?ResponseInterface $response = null;

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->response = $this->client->request($method, $url, $options);
        }
    };
    $provider = new LlamaServerProvider(model: 'local', httpClient: $client);

    $events = $provider->streamEvents([new UserMessage('hi')]);
    foreach ($events as $event) {
        break;
    }

    expect($event)->toEqual(new TextDelta('a'))
        ->and($client->response?->getInfo('canceled'))->not->toBeTrue();

    unset($events, $event);

    expect($client->response?->getInfo('canceled'))->toBeTrue();
});

test('an Agent on LlamaServerProvider stops mid-stream on cancel and keeps the streamed text', function () {
    $client = new ScriptedStreamClient([
        [new FirstChunk(), new DataChunk(0, llamaServerBody([llamaServerChunk(['content' => 'Hel'])], false)), ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, llamaServerBody([llamaServerChunk(['content' => 'lo'])], false)), ScriptedStreamClient::TIMEOUT],
        [new DataChunk(0, llamaServerBody([llamaServerChunk([], 'stop')])), new LastChunk()],
    ]);
    $provider = (new LlamaServerProvider(model: 'local', httpClient: $client))
        ->withRequestLimits(new RequestLimits(idleTimeout: 5.0, tickInterval: 0.05));
    $token = new class implements CancellationTokenInterface {
        public bool $cancelled = false;

        public function isCancelled(): bool
        {
            return $this->cancelled;
        }
    };
    $agent = new Agent(provider: $provider, cancellationToken: $token);
    $agent->attach(new CallbackObserver(function (string $event, mixed $data) use ($token): void {
        if ($event === 'agent.text_delta' && $data === 'Hel') {
            $token->cancelled = true;
        }
    }));

    $output = $agent->run(new UserMessage('Say hello.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toBe('Hel')
        ->and(count($client->timeouts))->toBe(1);
});
