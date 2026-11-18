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

test('a stream error event reaches the agent output and agent.error sanitised and bounded', function () {
    $sse = 'data: ' . json_encode(['type' => 'response.output_text.delta', 'delta' => 'Partial']) . "\n\n"
        . 'data: ' . json_encode(['type' => 'error', 'code' => "evil\ncode", 'message' => "Bad\x1b[2J\r\n" . str_repeat('x', 5000)]) . "\n\n";
    $provider = new \CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider(
        model: 'gpt-4o',
        apiKey: 'k',
        httpClient: new MockHttpClient([new MockResponse($sse, ['http_code' => 200])]),
    );
    $agent = new StreamErrorTestAgent(provider: $provider);
    $errors = [];
    $agent->attach(new class ($errors) implements SplObserver {
        public function __construct(private array &$errors) {}

        public function update(SplSubject $subject): void
        {
            if ($subject->lastEvent() === 'agent.error') {
                $this->errors[] = $subject->lastEventData();
            }
        }
    });

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->finishReason)->toBe(\CarmeloSantana\PHPAgents\Enum\AgentFinishReason::Error)
        ->and($output->content)->toStartWith('Provider error: error: Bad [2J x')
        ->and($output->content)->toEndWith('…')
        // The agent bounds the whole exception message (type included) to 300 characters.
        ->and(mb_strlen($output->content))->toBe(mb_strlen('Provider error: ') + 301)
        ->and(preg_match('/\p{Cc}/u', $output->content))->toBe(0)
        ->and($errors)->toBe([substr($output->content, strlen('Provider error: '))]);
});
