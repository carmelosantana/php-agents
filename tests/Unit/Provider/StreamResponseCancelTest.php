<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// A provider keeps a failed stream's HTTP response open only when the exception is an HTTP
// status error whose body the caller still reads; any other exception after a successful
// status (a parser limit, a provider error event) cancels the response explicitly.

/**
 * A client that keeps the response it handed out (MockHttpClient returns a copy).
 */
function cancelCapturingClient(MockResponse $response): HttpClientInterface
{
    return new class (new MockHttpClient([$response])) implements HttpClientInterface {
        use DecoratorTrait;

        public ?ResponseInterface $response = null;

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->response = $this->client->request($method, $url, $options);
        }
    };
}

/**
 * Drain $class's streamEvents() over $body with $status; returns the exception and the response.
 *
 * @param class-string<ProviderInterface> $class
 * @return array{\Throwable|null, ResponseInterface|null}
 */
function cancelDrain(string $class, string $body, int $status = 200): array
{
    $client = cancelCapturingClient(new MockResponse($body, ['http_code' => $status]));
    $provider = new $class(model: 'test-model', apiKey: 'key', httpClient: $client);
    $thrown = null;

    try {
        iterator_to_array($provider->streamEvents([new UserMessage('hi')]), false);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    /** @var ResponseInterface|null $response */
    $response = $client->response;

    return [$thrown, $response];
}

$httpProviders = [
    'OpenAI-compatible' => OpenAICompatibleProvider::class,
    'Anthropic' => AnthropicProvider::class,
    'Gemini' => GeminiProvider::class,
    'Responses' => OpenAIResponsesProvider::class,
];

test('a stream line over the SSE cap cancels the successful response', function (string $class) {
    [$thrown, $response] = cancelDrain($class, 'data: "' . str_repeat('x', 1_048_600) . "\"\n\n");

    expect($thrown)->toBeInstanceOf(ProviderException::class)
        ->and($response?->getInfo('canceled'))->toBeTrue();
})->with($httpProviders);

test('a provider error event cancels the successful response', function (string $class, string $body) {
    [$thrown, $response] = cancelDrain($class, $body);

    expect($thrown)->toBeInstanceOf(ProviderException::class)
        ->and($response?->getInfo('canceled'))->toBeTrue();
})->with([
    'Anthropic' => [AnthropicProvider::class, 'event: error' . "\n" . 'data: {"type":"error","error":{"type":"overloaded_error","message":"busy"}}' . "\n\n"],
    'Responses' => [OpenAIResponsesProvider::class, 'data: {"type":"error","code":"server_error","message":"busy"}' . "\n\n"],
]);

test('an HTTP status error keeps the response readable for its error body', function (string $class) {
    [$thrown, $response] = cancelDrain($class, '{"error":{"message":"bad request"}}', 400);

    expect($thrown)->toBeInstanceOf(HttpExceptionInterface::class)
        ->and($response?->getInfo('canceled'))->not->toBeTrue()
        ->and($response?->getContent(false))->toContain('bad request');
})->with($httpProviders);
