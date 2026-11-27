<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\StructuredStreaming;
use CarmeloSantana\PHPAgents\Exception\HttpStatusException;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\SseStreamParser;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Support\Http\ScriptedStreamClient;

final class StreamHttpStatusTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a stream status test agent.';
    }
}

function errorResponse(int $status): MockResponse
{
    return new MockResponse('{"error":{"message":"secret detail"}}', ['http_code' => $status, 'response_headers' => ['Content-Type: application/json']]);
}

/**
 * A parser over a scripted $status answer whose body chunk sets $bodyRead when the parser pulls it.
 *
 * @return array{SseStreamParser, ResponseInterface}
 */
function observableStatusParser(int $status, bool &$bodyRead): array
{
    $client = new ScriptedStreamClient([[
        new FirstChunk(),
        function () use (&$bodyRead): void {
            $bodyRead = true;
        },
        new DataChunk(0, "data: {\"error\":\"secret detail\"}\n\n"),
        new LastChunk(),
    ]], status: $status);

    return [new SseStreamParser($client, $client->response), $client->response];
}

/**
 * A client that hands out $response through a MockHttpClient and keeps the response it issued.
 */
function recordingClient(MockResponse $response): HttpClientInterface
{
    return new class ($response) implements HttpClientInterface {
        use DecoratorTrait {
            request as private decoratedRequest;
        }

        public ?ResponseInterface $issued = null;

        public function __construct(MockResponse $response)
        {
            $this->client = new MockHttpClient([$response]);
        }

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            return $this->issued = $this->decoratedRequest($method, $url, $options);
        }
    };
}

function statusStreamCatch(iterable $events): ?Throwable
{
    try {
        foreach ($events as $_) {
        }
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

test('the parser throws a status-only client exception on a 4xx without reading the body', function () {
    $bodyRead = false;
    [$parser, $response] = observableStatusParser(401, $bodyRead);

    $thrown = statusStreamCatch($parser->events());

    expect($thrown)->toBeInstanceOf(HttpStatusException::class)
        ->and($thrown)->toBeInstanceOf(ProviderException::class)
        ->and($thrown)->toBeInstanceOf(ClientExceptionInterface::class)
        ->and($thrown)->not->toBeInstanceOf(ServerExceptionInterface::class)
        ->and($thrown?->getMessage())->toBe('Provider returned HTTP 401')
        ->and($thrown?->getStatusCode())->toBe(401)
        ->and($thrown?->getResponse())->toBe($response)
        ->and($bodyRead)->toBeFalse();
});

test('the parser throws a status-only server exception on a 5xx', function () {
    $bodyRead = false;
    [$parser] = observableStatusParser(503, $bodyRead);

    $thrown = statusStreamCatch($parser->events());

    expect($thrown)->toBeInstanceOf(HttpStatusException::class)
        ->and($thrown)->toBeInstanceOf(ServerExceptionInterface::class)
        ->and($thrown)->not->toBeInstanceOf(ClientExceptionInterface::class)
        ->and($thrown?->getMessage())->toBe('Provider returned HTTP 503')
        ->and($bodyRead)->toBeFalse();
});

test('the parser leaves the response open, and a caller that does not want the body cancels it', function () {
    $client = new MockHttpClient([errorResponse(429)]);
    $response = $client->request('POST', 'https://api.example.test/v1/stream');

    $thrown = statusStreamCatch((new SseStreamParser($client, $response))->events());
    $openAfterThrow = $response->getInfo('canceled') === false;
    $thrown?->getResponse()->cancel();

    expect($thrown)->toBeInstanceOf(HttpStatusException::class)
        ->and($openAfterThrow)->toBeTrue()
        ->and($response->getInfo('canceled'))->toBeTrue();
});

/** @return array<string, array{Closure(MockHttpClient): StructuredStreaming}> */
function statusStreamProviders(): array
{
    return [
        'OpenAICompatible' => [fn(MockHttpClient $c) => new OpenAICompatibleProvider('m', 'https://api.example.test/v1', httpClient: $c)],
        'OpenAIResponses' => [fn(MockHttpClient $c) => new OpenAIResponsesProvider('m', 'https://api.example.test/v1', 'k', $c)],
        'Anthropic' => [fn(MockHttpClient $c) => new AnthropicProvider('m', 'https://api.example.test/v1', 'k', $c)],
        'Gemini' => [fn(MockHttpClient $c) => new GeminiProvider('m', 'https://api.example.test/v1beta', 'k', $c)],
    ];
}

test('streamEvents and structuredEvents throw the status-only exception on a 429', function (Closure $make) {
    foreach (['streamEvents', 'structuredEvents'] as $path) {
        $client = new MockHttpClient([errorResponse(429)]);
        $provider = $make($client);
        $messages = [new UserMessage('Hi')];

        $events = $path === 'streamEvents'
            ? $provider->streamEvents($messages)
            : $provider->structuredEvents($messages, '{"type":"object"}');
        $thrown = statusStreamCatch($events);

        expect($thrown)->toBeInstanceOf(HttpStatusException::class, $path)
            ->and($thrown?->getMessage())->toBe('Provider returned HTTP 429')
            ->and($thrown?->getStatusCode())->toBe(429);
    }
})->with(statusStreamProviders());

test('the usage operation option never reaches the request body on either streaming path', function (Closure $make) {
    foreach (['streamEvents', 'structuredEvents'] as $path) {
        $response = new MockResponse('', ['http_code' => 200]);
        $provider = $make(new MockHttpClient([$response]));
        $messages = [new UserMessage('Hi')];
        $options = [UsageReport::OPERATION_OPTION => 'caller-supplied'];

        $events = $path === 'streamEvents'
            ? $provider->streamEvents($messages, [], $options)
            : $provider->structuredEvents($messages, '{"type":"object"}', $options);
        statusStreamCatch($events);

        $body = json_decode($response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR);
        expect($body)->not->toHaveKey(UsageReport::OPERATION_OPTION, $path)
            ->and(json_encode($body))->not->toContain('__php_agents_usage_operation');
    }
})->with(statusStreamProviders());

test('the agent keeps the bounded API message from a streamed 4xx and then cancels the response', function () {
    $response = new MockResponse('{"error":{"message":"invalid x-api-key"}}', [
        'http_code' => 401,
        'response_headers' => ['Content-Type: application/json'],
    ]);
    $client = recordingClient($response);
    $provider = new OpenAICompatibleProvider('m', 'https://api.example.test/v1', httpClient: $client);

    $output = (new StreamHttpStatusTestAgent(provider: $provider))->run(new UserMessage('Hi'));

    expect($output->content)->toContain('Provider returned HTTP 401')
        ->and($output->content)->toContain("\n\nAPI response: invalid x-api-key")
        ->and($client->issued?->getInfo('canceled'))->toBeTrue();
});
