<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StreamErrorTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a stream-error test agent.';
    }
}

test('a streamed 401 keeps the API error message in the agent output', function () {
    $client = new MockHttpClient([
        new MockResponse('{"error":{"message":"invalid x-api-key"}}', [
            'http_code' => 401,
            'response_headers' => ['Content-Type: application/json'],
        ]),
    ]);
    $provider = new OpenAICompatibleProvider(
        model: 'test-model',
        baseUrl: 'https://api.example.test/v1',
        httpClient: $client,
    );

    $output = (new StreamErrorTestAgent(provider: $provider))->run(new UserMessage('Hi'));

    expect($output->content)->toContain('401')
        ->and($output->content)->toContain('invalid x-api-key');
});
