<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use CarmeloSantana\PHPAgents\Contract\CliRuntimeInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Contract\UsageReporting;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\Cli\ClaudeCliVendorAdapter;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessChunk;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessRequest;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessResult;
use CarmeloSantana\PHPAgents\Provider\CliProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaServerProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Provider\UsageReport;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use LogicException;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

final class UsageFixtures
{
    public const MARKER = 'PHPAGENTS-SECRET-MARKER';

    public const PENDING = [
        CliProvider::class,
    ];

    private const FORMATS = [
        AnthropicProvider::class => 'anthropic',
        CliProvider::class => 'cli',
        GeminiProvider::class => 'gemini',
        LlamaServerProvider::class => 'chat-completions',
        MistralProvider::class => 'chat-completions',
        OllamaProvider::class => 'chat-completions',
        OpenAICompatibleProvider::class => 'chat-completions',
        OpenAIResponsesProvider::class => 'responses',
        XAIProvider::class => 'chat-completions',
    ];

    public static function has(string $providerClass): bool
    {
        return isset(self::FORMATS[$providerClass]);
    }

    public static function for(string $providerClass): UsageFixture
    {
        return new UsageFixture(
            $providerClass,
            self::FORMATS[$providerClass] ?? throw new LogicException("add a usage fixture for {$providerClass}"),
        );
    }
}

final class UsageFixture implements UsageListener
{
    private const MODEL = 'fixture-model';
    private const VENDOR_MODEL = 'fixture-vendor-model';
    private const ANSWER = '{"answer":"' . UsageFixtures::MARKER . '"}';
    private const SCHEMA = '{"type":"object","properties":{"answer":{"type":"string","description":"'
        . UsageFixtures::MARKER . '"}},"required":["answer"],"additionalProperties":false}';
    private const OPEN_SCHEMA = '{"type":"object","description":"' . UsageFixtures::MARKER . '"}';
    private const INVALID_SCHEMA = UsageFixtures::MARKER;
    private const CLAUDE_USAGE = ['input_tokens' => 12, 'output_tokens' => 5];
    private const CHAT_USAGE = ['prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17];
    private const GEMINI_USAGE = ['promptTokenCount' => 12, 'candidatesTokenCount' => 5, 'totalTokenCount' => 17];

    public readonly ProviderInterface $original;
    /** @var list<UsageReport> */
    public array $reports = [];
    /** @var list<string> */
    public array $inferenceRequests = [];
    /** @var list<array{string, array<string, mixed>}> */
    public array $warnings = [];

    private ?ProviderInterface $listeningProvider = null;
    private string $scenario = 'chat';
    private readonly TransportException $transportFailure;

    public function __construct(private readonly string $providerClass, private readonly string $format)
    {
        $this->transportFailure = new TransportException('Fixture connection failed');
        $logger = new class ($this) extends AbstractLogger {
            public function __construct(private readonly UsageFixture $fixture) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->fixture->warnings[] = [(string) $message, $context];
                }
            }
        };

        if ($providerClass === CliProvider::class) {
            $runtime = new class ($this) implements CliRuntimeInterface {
                public function __construct(private readonly UsageFixture $fixture) {}

                public function isAvailable(string $binary): bool
                {
                    return true;
                }

                public function run(CliProcessRequest $request): CliProcessResult
                {
                    return $this->fixture->cliResult($request);
                }

                public function stream(CliProcessRequest $request): iterable
                {
                    yield from $this->fixture->cliChunks($request);
                }
            };

            $adapter = new ClaudeCliVendorAdapter();
            $this->original = is_subclass_of(CliProvider::class, UsageReporting::class)
                ? new CliProvider(self::MODEL, $adapter, $runtime, logger: $logger)
                : new CliProvider(self::MODEL, $adapter, $runtime);

            return;
        }

        $client = new MockHttpClient($this->httpResponse(...));
        $this->original = $providerClass === OllamaProvider::class
            ? new OllamaProvider(self::MODEL, httpClient: $client, logger: $logger)
            : new $providerClass(self::MODEL, apiKey: 'fixture', httpClient: $client, logger: $logger);
    }

    public function provider(): ProviderInterface
    {
        return $this->listeningProvider ??= $this->attach($this);
    }

    public function onUsage(UsageReport $report): void
    {
        $this->reports[] = $report;
    }

    public function withThrowingListener(): ProviderInterface
    {
        return $this->attach(new class implements UsageListener {
            public function onUsage(UsageReport $report): void
            {
                throw new LogicException(UsageFixtures::MARKER);
            }
        });
    }

    public function withRequestLimits(): ProviderInterface
    {
        return $this->provider()->withRequestLimits(new RequestLimits(maxResponseBytes: 8192));
    }

    private function attach(UsageListener $listener): ProviderInterface
    {
        if (!$this->original instanceof UsageReporting) {
            throw new LogicException($this->providerClass . ' must implement UsageReporting');
        }

        return $this->original->withUsageListener($listener);
    }

    public function chat(?ProviderInterface $provider = null): Response
    {
        $this->scenario = 'chat';

        return ($provider ?? $this->provider())->chat(self::messages());
    }

    public function streamFull(): Finish
    {
        return $this->finishStream('stream');
    }

    public function streamAbandoned(): void
    {
        $this->scenario = 'stream';
        $stream = $this->provider()->streamEvents(self::messages());
        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                unset($stream);

                return;
            }
        }

        throw new LogicException('Expected a text delta to abandon the stream');
    }

    public function truncatedStream(): Finish
    {
        return $this->finishStream('truncated');
    }

    private function finishStream(string $scenario): Finish
    {
        $this->scenario = $scenario;
        $finish = null;
        foreach ($this->provider()->streamEvents(self::messages()) as $event) {
            if ($finish !== null) {
                throw new LogicException('Unexpected event after Finish');
            }
            if ($event instanceof Finish) {
                $finish = $event;
            }
        }

        return $finish ?? throw new LogicException('Expected a Finish event');
    }

    /** @return array<string, callable(): mixed> */
    public function structuredPaths(): array
    {
        $schemas = match ($this->format) {
            'anthropic' => [
                'native JSON' => self::SCHEMA,
                'native text' => self::SCHEMA,
                'invalid schema' => self::INVALID_SCHEMA,
                'prompt fallback JSON' => self::OPEN_SCHEMA,
                'prompt fallback text' => self::OPEN_SCHEMA,
            ],
            'responses' => [
                'native function call' => self::SCHEMA,
                'native message' => self::SCHEMA,
                'invalid schema' => self::INVALID_SCHEMA,
            ],
            'gemini' => [
                'response schema' => self::SCHEMA,
                'invalid schema' => self::INVALID_SCHEMA,
                'non-object schema' => '{"schema":"' . UsageFixtures::MARKER . '"}',
            ],
            'cli' => ['plain JSON' => self::SCHEMA, 'embedded JSON' => self::SCHEMA, 'plain text' => self::SCHEMA],
            'chat-completions' => ['response format' => self::SCHEMA, 'invalid schema' => self::INVALID_SCHEMA],
        };

        $paths = [];
        foreach ($schemas as $path => $schema) {
            $paths[$path] = function () use ($path, $schema): mixed {
                $this->scenario = $path;

                return $this->provider()->structured(self::messages(), $schema);
            };
        }

        return $paths;
    }

    public function http500(?ProviderInterface $provider = null): Throwable
    {
        return $this->failedChat('server error', $provider);
    }

    public function transportError(?ProviderInterface $provider = null): Throwable
    {
        return $this->failedChat('transport error', $provider);
    }

    private function failedChat(string $scenario, ?ProviderInterface $provider): Throwable
    {
        $this->scenario = $scenario;
        $provider ??= $this->provider();
        try {
            $provider->chat(self::messages());
        } catch (Throwable $exception) {
            return $exception;
        }

        throw new LogicException('Expected the request to throw');
    }

    /** @return array{requestId: ?string, responseId: ?string} */
    public function expectedIds(): array
    {
        return [
            'requestId' => match ($this->providerClass) {
                AnthropicProvider::class, MistralProvider::class, OpenAICompatibleProvider::class,
                OpenAIResponsesProvider::class, XAIProvider::class => 'fixture-request',
                default => null,
            },
            'responseId' => match ($this->format) {
                'anthropic' => 'msg_fixture',
                'chat-completions' => 'chatcmpl-fixture',
                'responses' => 'resp_fixture',
                'gemini' => 'gemini-fixture',
                'cli' => null,
            },
        ];
    }

    public function abandonedUsage(): ?Usage
    {
        return $this->format === 'anthropic' ? new Usage(12, 0, 12) : null;
    }

    public function structuredUsage(): Usage
    {
        return new Usage(12, 5, 17);
    }

    public function expectedModel(): string
    {
        return self::VENDOR_MODEL;
    }

    /** @return list<MessageInterface> */
    private static function messages(): array
    {
        return [
            new UserMessage(UsageFixtures::MARKER),
            new AssistantMessage(toolCalls: [new ToolCall('fixture-call', 'lookup', ['query' => UsageFixtures::MARKER])]),
            new ToolResultMessage(ToolResult::success('fixture result')->withCallId('fixture-call')),
            new UserMessage('Continue'),
        ];
    }

    /** @param array<string, mixed> $options */
    private function httpResponse(string $method, string $url, array $options): MockResponse
    {
        if ($method === 'GET') {
            return new MockResponse('{"data":[],"models":[]}');
        }

        $this->inferenceRequests[] = $options['body'];
        if ($this->scenario === 'transport error') {
            throw $this->transportFailure;
        }

        $headers = ['content-type: application/json'];
        $requestId = $this->expectedIds()['requestId'];
        if ($requestId !== null) {
            $header = $this->format === 'anthropic' ? 'request-id' : 'x-request-id';
            $headers[] = "{$header}: {$requestId}";
        }

        if ($this->scenario === 'server error') {
            return new MockResponse('{"error":{"message":"Fixture server failed"}}', [
                'http_code' => 500,
                'response_headers' => $headers,
            ]);
        }

        $streaming = in_array($this->scenario, ['stream', 'truncated'], true);
        if ($streaming) {
            $headers[0] = 'content-type: text/event-stream';
        }

        return new MockResponse(
            $streaming ? $this->sse($this->scenario === 'truncated') : self::json($this->responseBody()),
            ['response_headers' => $headers],
        );
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        $id = $this->expectedIds()['responseId'];
        $text = $this->answer();
        $model = self::VENDOR_MODEL;

        return match ($this->format) {
            'anthropic' => [
                'id' => $id, 'model' => $model, 'stop_reason' => 'end_turn', 'usage' => self::CLAUDE_USAGE,
                'content' => [['type' => 'text', 'text' => $text]],
            ],
            'chat-completions' => [
                'id' => $id, 'model' => $model, 'usage' => self::CHAT_USAGE,
                'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
            ],
            'responses' => [
                'id' => $id, 'model' => $model, 'status' => 'completed', 'usage' => self::CLAUDE_USAGE,
                'output' => $this->scenario === 'native function call'
                    ? [['type' => 'function_call', 'name' => 'structured_output', 'arguments' => $text]]
                    : [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            ],
            'gemini' => [
                'responseId' => $id, 'modelVersion' => $model, 'usageMetadata' => self::GEMINI_USAGE,
                'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
            ],
        };
    }

    private function answer(): string
    {
        return match ($this->scenario) {
            'native text', 'prompt fallback text', 'plain text' => UsageFixtures::MARKER,
            'embedded JSON' => "Result:\n" . self::ANSWER,
            default => self::ANSWER,
        };
    }

    private function sse(bool $truncated): string
    {
        $text = UsageFixtures::MARKER;
        $response = $this->responseBody();
        $id = $this->expectedIds()['responseId'];
        $model = self::VENDOR_MODEL;

        [$opening, $closing] = match ($this->format) {
            'anthropic' => [[
                ['type' => 'message_start', 'message' => ['id' => $id, 'model' => $model, 'usage' => ['input_tokens' => 12, 'output_tokens' => 0]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
            ], [
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
                ['type' => 'message_stop'],
            ]],
            'chat-completions' => [[
                ['id' => $id, 'model' => $model, 'choices' => [['delta' => ['content' => $text]]]],
            ], [
                ['id' => $id, 'model' => $model, 'choices' => [['delta' => [], 'finish_reason' => 'stop']]],
                ['id' => $id, 'model' => $model, 'choices' => [], 'usage' => self::CHAT_USAGE],
                '[DONE]',
            ]],
            'responses' => [[
                ['type' => 'response.created', 'response' => ['id' => $id, 'model' => $model]],
                ['type' => 'response.output_text.delta', 'delta' => $text],
            ], [
                ['type' => 'response.completed', 'response' => $response],
            ]],
            'gemini' => [[
                ['responseId' => $id, 'modelVersion' => $model, 'candidates' => [['content' => ['parts' => [['text' => $text]]]]]],
            ], [$response]],
        };

        $body = '';
        foreach ($truncated ? $opening : [...$opening, ...$closing] as $event) {
            $body .= 'data: ' . (is_string($event) ? $event : self::json($event)) . "\n\n";
        }

        return $body;
    }

    public function cliResult(CliProcessRequest $request): CliProcessResult
    {
        $this->inferenceRequests[] = serialize($request);
        if ($this->scenario === 'transport error') {
            throw $this->transportFailure;
        }
        if ($this->scenario === 'server error') {
            // A nonzero process exit is the CLI equivalent of an HTTP server error.
            return new CliProcessResult(1, '', 'Fixture process failed');
        }

        return new CliProcessResult(0, self::json($this->cliAnswer()));
    }

    /** @return iterable<CliProcessChunk> */
    public function cliChunks(CliProcessRequest $request): iterable
    {
        $this->inferenceRequests[] = serialize($request);
        yield new CliProcessChunk(self::json([
            'type' => 'stream_event',
            'event' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => UsageFixtures::MARKER]],
        ]) . "\n");

        if ($this->scenario === 'truncated') {
            yield new CliProcessChunk(isLast: true, exitCode: 1, error: 'Fixture process stopped before the result');

            return;
        }

        yield new CliProcessChunk(self::json(['type' => 'result', ...$this->cliAnswer()]) . "\n", isLast: true);
    }

    /** @return array<string, mixed> */
    private function cliAnswer(): array
    {
        return ['result' => $this->answer(), 'model' => self::VENDOR_MODEL, 'stop_reason' => 'end_turn', 'usage' => self::CLAUDE_USAGE];
    }

    /** @param array<mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
