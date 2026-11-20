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

test('an observer that throws mid-stream escapes run() instead of reading as a provider error', function (string $event, bool $cancel) {
    $token = new class implements \CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface {
        public bool $cancelled = false;

        public function isCancelled(): bool
        {
            return $this->cancelled;
        }

        public function cancel(): void
        {
            $this->cancelled = true;
        }
    };
    $agent = new StreamErrorTestAgent(
        provider: new \Tests\Support\Agent\ScriptedProvider([new \CarmeloSantana\PHPAgents\Provider\Response(
            content: 'Hello',
            finishReason: \CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop,
        )]),
        cancellationToken: $token,
    );
    $events = [];
    $agent->attach(new \CarmeloSantana\PHPAgents\Agent\CallbackObserver(function (string $name) use (&$events, $event, $cancel, $token): void {
        $events[] = $name;
        if ($name === $event) {
            if ($cancel) {
                $token->cancel();
            }
            throw new \DomainException('observer broke');
        }
    }));

    expect(fn() => $agent->run(new UserMessage('Hi')))->toThrow(\DomainException::class, 'observer broke')
        ->and($events)->not->toContain('agent.error');
})->with([
    'text delta' => ['agent.text_delta', false],
    'step finish' => ['agent.step_finish', false],
    'text delta after a cancel' => ['agent.text_delta', true],
]);

test('an observer exception is forgotten once its run ends', function () {
    $broken = new \DomainException('observer broke');
    $provider = new class ($broken) implements \CarmeloSantana\PHPAgents\Contract\ProviderInterface {
        use \CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

        public int $calls = 0;

        public function __construct(private \Throwable $reused) {}

        public function chat(array $messages, array $tools = [], array $options = []): \CarmeloSantana\PHPAgents\Provider\Response
        {
            throw new LogicException('unused');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            $this->calls++;
            yield new \CarmeloSantana\PHPAgents\Provider\Response(content: 'Hello', finishReason: \CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop);
            if ($this->calls === 2) {
                // A provider that rethrows the same object must still read as a provider error.
                throw $this->reused;
            }
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            throw new LogicException('unused');
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return 'test-model';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
    $agent = new StreamErrorTestAgent(provider: $provider);
    $throwing = true;
    $agent->attach(new \CarmeloSantana\PHPAgents\Agent\CallbackObserver(function (string $name) use (&$throwing, $broken): void {
        if ($throwing && $name === 'agent.text_delta') {
            throw $broken;
        }
    }));

    expect(fn() => $agent->run(new UserMessage('Hi')))->toThrow(\DomainException::class);

    $throwing = false;
    $output = $agent->run(new UserMessage('Again'));

    expect($output->finishReason)->toBe(\CarmeloSantana\PHPAgents\Enum\AgentFinishReason::Error)
        ->and($output->content)->toContain('observer broke');
});
