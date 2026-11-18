<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

function limitsTestProvider(string $name, HttpClientInterface $client): AbstractProvider
{
    return match ($name) {
        'anthropic' => new AnthropicProvider(apiKey: 'test-key', httpClient: $client),
        'gemini' => new GeminiProvider(apiKey: 'test-key', httpClient: $client),
        'openai-compatible' => new OpenAICompatibleProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $client),
        'openai-responses' => new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'test-key', httpClient: $client),
        'ollama' => new OllamaProvider(httpClient: $client),
        'mistral' => new MistralProvider(apiKey: 'test-key', httpClient: $client),
        'xai' => new XAIProvider(model: 'grok-3', apiKey: 'test-key', httpClient: $client),
    };
}

/** A minimal valid non-streaming chat body for each provider. */
function limitsTestChatBody(string $name): string
{
    $body = match ($name) {
        'anthropic' => [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'hi']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ],
        'gemini' => [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'hi']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        ],
        'openai-responses' => [
            'id' => 'resp_1',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'hi']],
            ]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
        ],
        default => [
            'id' => 'chatcmpl-1',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'hi'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ],
    };

    return json_encode($body, JSON_THROW_ON_ERROR);
}

/**
 * A MockHttpClient that answers every request with $body and records each request.
 *
 * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
 * @param array<string, mixed> $info
 */
function limitsTestClient(array &$captured, string $body, array $info = ['http_code' => 200]): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $body, $info): MockResponse {
        $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse($body, $info);
    });
}

/** Messages of $e and every previous throwable, joined. */
function limitsTestMessageChain(Throwable $e): string
{
    $messages = [];
    for ($current = $e; $current !== null; $current = $current->getPrevious()) {
        $messages[] = $current->getMessage();
    }

    return implode(' <- ', $messages);
}

/** Runs $fn and returns what it threw, or null. */
function limitsTestCatch(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * @param array<string, mixed> $options
 */
function expectDefaultLimits(array $options): void
{
    expect($options['max_redirects'] ?? null)->toBe(0)
        ->and($options['max_duration'] ?? null)->toBe(600.0)
        ->and(is_callable($options['on_progress'] ?? null))->toBeTrue();
}

dataset('limited providers', [
    'anthropic',
    'gemini',
    'openai-compatible',
    'openai-responses',
    'ollama',
    'mistral',
    'xai',
]);

test('chat() sends max_redirects 0 and max_duration 600.0', function (string $name) {
    $captured = [];
    $provider = limitsTestProvider($name, limitsTestClient($captured, limitsTestChatBody($name)));

    $provider->chat([new UserMessage('Hi')]);

    expect($captured)->toHaveCount(1);
    expectDefaultLimits($captured[0]['options']);
    expect($captured[0]['options']['timeout'])->toBe(300.0);
})->with('limited providers');

test('stream() sends the same limits', function (string $name) {
    $captured = [];
    $provider = limitsTestProvider($name, limitsTestClient($captured, ''));

    iterator_to_array($provider->stream([new UserMessage('Hi')]), false);

    expect($captured)->toHaveCount(1);
    expectDefaultLimits($captured[0]['options']);
})->with('limited providers');

test('models() sends the same limits', function (string $name) {
    $captured = [];
    $provider = limitsTestProvider($name, limitsTestClient($captured, '{}'));

    $provider->models();

    expect($captured)->not->toBeEmpty();
    foreach ($captured as $request) {
        expectDefaultLimits($request['options']);
    }
})->with('limited providers');

test('isAvailable() sends the same limits', function (string $name) {
    $captured = [];
    $provider = limitsTestProvider($name, limitsTestClient($captured, '{}'));

    $provider->isAvailable();

    expect($captured)->toHaveCount(1);
    expectDefaultLimits($captured[0]['options']);
    expect($captured[0]['options']['timeout'])->toBe(5.0);
})->with('limited providers');

test('withRequestLimits() applies to later requests and leaves the original untouched', function (string $name) {
    $captured = [];
    $original = limitsTestProvider($name, limitsTestClient($captured, limitsTestChatBody($name)));
    $limits = new RequestLimits(maxDuration: 45.0);

    $limited = $original->withRequestLimits($limits);
    $limited->chat([new UserMessage('Hi')]);
    $original->chat([new UserMessage('Hi')]);

    expect($limited)->not->toBe($original)
        ->and($limited->requestLimits())->toBe($limits)
        ->and($original->requestLimits()->maxDuration)->toBe(600.0)
        ->and($captured[0]['options']['max_duration'])->toBe(45.0)
        ->and($captured[1]['options']['max_duration'])->toBe(600.0);
})->with('limited providers');

test('a 307 to another host is refused, not followed', function (string $name) {
    $captured = [];
    $provider = limitsTestProvider($name, limitsTestClient($captured, '', [
        'http_code' => 307,
        'response_headers' => ['Location: https://evil.example/'],
    ]));

    $thrown = limitsTestCatch(fn () => $provider->chat([new UserMessage('Hi')]));

    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and(limitsTestMessageChain($thrown))->toContain('redirect')
        ->and($captured)->toHaveCount(1);
})->with('limited providers');

test('a body over the cap is refused', function (string $name) {
    $captured = [];
    $provider = limitsTestProvider($name, limitsTestClient($captured, limitsTestChatBody($name)))
        ->withRequestLimits(new RequestLimits(maxResponseBytes: 10));

    $thrown = limitsTestCatch(fn () => $provider->chat([new UserMessage('Hi')]));

    expect($thrown)->toBeInstanceOf(Throwable::class)
        ->and(limitsTestMessageChain($thrown))->toContain('exceeded');
})->with('limited providers');

test('Ollama models() sends the limits on /api/tags and /api/show', function () {
    $captured = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
        $captured[] = ['url' => $url, 'options' => $options];

        return str_ends_with($url, '/api/tags')
            ? new MockResponse(json_encode(['models' => [['name' => 'llama3.2']]], JSON_THROW_ON_ERROR))
            : new MockResponse('{}');
    });

    (new OllamaProvider(httpClient: $client))->models();

    expect(array_column($captured, 'url'))->toBe([
        'http://localhost:11434/api/tags',
        'http://localhost:11434/api/show',
    ]);
    expectDefaultLimits($captured[0]['options']);
    expectDefaultLimits($captured[1]['options']);
    expect($captured[1]['options']['timeout'])->toBe(10.0);
});

test('Ollama pull keeps no total limit', function () {
    $captured = [];
    $provider = (new OllamaProvider(httpClient: limitsTestClient($captured, '{}')))
        ->withRequestLimits(new RequestLimits(idleTimeout: 42.0, maxResponseBytes: 10));

    $provider->pull('llama3.2');

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['url'])->toBe('http://localhost:11434/api/pull')
        // symfony fills an omitted max_duration with its default 0, which means no limit.
        ->and((float) ($captured[0]['options']['max_duration'] ?? 0))->toBe(0.0)
        ->and($captured[0]['options']['max_redirects'])->toBe(0)
        ->and($captured[0]['options']['timeout'])->toBe(42.0);

    // No byte cap: the progress callback accepts any size.
    $captured[0]['options']['on_progress'](PHP_INT_MAX, 0, []);
});

/**
 * Starts `php -S` on a free local port with a router that answers /cap/* with a 4 KiB
 * JSON body, /redirect/* with a 307 to /leak, and /leak by touching $dir/leaked.
 * Runs $fn with the base URL, then stops the server.
 */
function limitsTestWithLocalServer(callable $fn): void
{
    $dir = sys_get_temp_dir() . '/php-agents-limits-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $router = $dir . '/router.php';
    file_put_contents($router, <<<'PHP'
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (str_starts_with($path, '/redirect/')) {
            header('Location: /leak', true, 307);
            return;
        }
        if ($path === '/leak') {
            touch(__DIR__ . '/leaked');
        }
        header('Content-Type: application/json');
        echo json_encode(['pad' => str_repeat('x', 4096)]);
        PHP);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = $socket === false ? '' : (string) stream_socket_get_name($socket, false);
    if ($socket !== false) {
        fclose($socket);
    }
    $port = (int) substr($name, strrpos($name, ':') + 1);

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    if ($process === false) {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
        test()->markTestSkipped('proc_open() could not start php -S.');
    }

    try {
        // Wait for php -S to accept connections; a refused probe is expected meanwhile.
        $deadline = microtime(true) + 5.0;
        set_error_handler(static fn (): bool => true);
        try {
            while (($probe = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) === false) {
                if (microtime(true) > $deadline) {
                    test()->markTestSkipped('php -S did not start.');
                }
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }
        fclose($probe);

        $fn("http://127.0.0.1:{$port}", $dir);
    } finally {
        proc_terminate($process);
        proc_close($process);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}

function limitsTestRealClient(string $name): HttpClientInterface
{
    if ($name === 'curl' && !extension_loaded('curl')) {
        test()->markTestSkipped('ext-curl is not loaded.');
    }

    return $name === 'curl' ? new CurlHttpClient() : new NativeHttpClient();
}

test('a real HTTP client surfaces the cap from on_progress the same way', function (string $clientName) {
    limitsTestWithLocalServer(function (string $baseUrl) use ($clientName): void {
        $provider = (new OpenAICompatibleProvider(model: 'gpt-4o', baseUrl: "{$baseUrl}/cap/v1", httpClient: limitsTestRealClient($clientName)))
            ->withRequestLimits(new RequestLimits(maxResponseBytes: 100));

        $thrown = limitsTestCatch(fn () => $provider->chat([new UserMessage('Hi')]));

        expect($thrown)->toBeInstanceOf(TransportExceptionInterface::class)
            ->and($thrown?->getPrevious())->toBeInstanceOf(ProviderException::class)
            ->and(limitsTestMessageChain($thrown))->toContain('exceeded 100 bytes');
    });
})->with(['native', 'curl']);

test('a real HTTP client does not follow a 307 and reports the refused redirect', function (string $clientName) {
    limitsTestWithLocalServer(function (string $baseUrl, string $dir) use ($clientName): void {
        $provider = new OpenAICompatibleProvider(model: 'gpt-4o', baseUrl: "{$baseUrl}/redirect/v1", apiKey: 'secret', httpClient: limitsTestRealClient($clientName));

        $thrown = limitsTestCatch(fn () => $provider->chat([new UserMessage('Hi')]));

        expect($thrown)->toBeInstanceOf(ProviderException::class)
            ->and(limitsTestMessageChain($thrown))->toContain('redirect')
            ->and(file_exists($dir . '/leaked'))->toBeFalse();
    });
})->with(['native', 'curl']);
