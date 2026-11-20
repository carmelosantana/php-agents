<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Provider error bodies, provider exception messages and tool error text are untrusted: they can
// carry credentials and terminal control bytes, and they reach Output, agent events, the
// conversation and the model. These pin the redaction and the bounds.

final class RedactionScriptedProvider implements ProviderInterface
{
    use StreamsEventsFromResponses;

    /** @param list<list<Response>|\Throwable> $streams */
    public function __construct(private array $streams) {}

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('unused');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $next = array_shift($this->streams) ?? throw new RuntimeException('No scripted stream left.');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        yield from $next;
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        throw new LogicException('unused');
    }

    public function models(): array
    {
        return [new ModelDefinition(id: 'test-model', name: 'Test Model', provider: 'test', capabilities: [ModelCapability::Text, ModelCapability::Tools])];
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
}

final class RedactionEventRecorder implements SplObserver
{
    /** @var list<array{string, mixed}> */
    public array $events = [];

    public function update(SplSubject $subject): void
    {
        /** @var AbstractAgent $subject */
        $this->events[] = [$subject->lastEvent(), $subject->lastEventData()];
    }

    /** @return list<mixed> */
    public function dataFor(string $event): array
    {
        return array_values(array_map(fn(array $e): mixed => $e[1], array_filter($this->events, fn(array $e): bool => $e[0] === $event)));
    }
}

final class RedactionTestAgent extends AbstractAgent
{
    /** @var list<ToolInterface> */
    public array $extraTools = [];

    public function instructions(): string
    {
        return 'You are a redaction test agent.';
    }

    public function tools(): array
    {
        return $this->extraTools;
    }
}

final class RedactionBatchExecutor implements BatchToolExecutorInterface
{
    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        return $tool->execute($arguments);
    }

    public function executeBatch(array $batch): array
    {
        return array_map(
            fn(array $entry): ToolResult => ToolResult::error("worker died: token=batch-secret-1 \x1b[0m"),
            $batch,
        );
    }
}

final class RedactionLeakyPolicy implements ToolExecutionPolicyInterface
{
    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        return "rule matched password=policy-pass-9\x07";
    }
}

/** A tool whose execute() throws, bypassing Tool's own catch. */
final class RedactionThrowingTool implements ToolInterface
{
    public function name(): string
    {
        return 'boom';
    }

    public function description(): string
    {
        return 'Throws.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        throw new RuntimeException('db down: Bearer abcdefgh12345678' . str_repeat(' long', 400));
    }

    public function toFunctionSchema(): array
    {
        return ['type' => 'function', 'function' => ['name' => 'boom', 'description' => 'Throws.', 'parameters' => ['type' => 'object', 'properties' => []]]];
    }
}

function redactionToolRound(array $calls): array
{
    return [new Response(content: '', finishReason: ProviderFinishReason::ToolUse, toolCalls: $calls, model: 'test-model')];
}

function redactionTextRound(): array
{
    return [new Response(content: 'Finished.', finishReason: ProviderFinishReason::Stop, model: 'test-model')];
}

/** Every error string the run exposed: Output.toolResults, the conversation, agent.tool_result and agent.tool_error. */
function redactionExposedErrors(Output $output, RedactionEventRecorder $events): array
{
    $texts = [];
    foreach ($output->toolResults as $r) {
        $texts[] = $r->content;
    }
    foreach ($output->conversation?->messages() ?? [] as $m) {
        if ($m instanceof ToolResultMessage) {
            $texts[] = $m->toArray()['content'] ?? '';
        }
    }
    foreach ($events->dataFor('agent.tool_result') as $r) {
        $texts[] = $r->content;
    }
    foreach ($events->dataFor('agent.tool_error') as $s) {
        $texts[] = $s;
    }

    return $texts;
}

function redactionHttpAgent(MockResponse $response): array
{
    $provider = new OpenAICompatibleProvider(
        model: 'test-model',
        baseUrl: 'https://api.example.test/v1',
        httpClient: new MockHttpClient([$response]),
    );
    $agent = new RedactionTestAgent(provider: $provider);
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    return [$agent, $events];
}

test('a 4xx body is bounded and redacted in Output and agent.error', function () {
    $body = '{"error":{"message":"bad key sk-ant-AAAAAAAAAAAAAAAAAAAAAAAA\u001b[31m"}}' . str_repeat(' ', 100 * 1024);
    [$agent, $events] = redactionHttpAgent(new MockResponse($body, ['http_code' => 401, 'response_headers' => ['Content-Type: application/json']]));

    $output = $agent->run(new UserMessage('Hi'));
    $errors = $events->dataFor('agent.error');

    expect($output->finishReason)->toBe(AgentFinishReason::Error)
        ->and($output->content)->toStartWith('Provider error: ')
        ->and($output->content)->toContain("\n\nAPI response: bad key [redacted] [31m")
        ->and($output->content)->not->toContain('sk-ant-AAAA')
        ->and($output->content)->not->toContain("\x1b")
        ->and(mb_strlen($output->content))->toBeLessThanOrEqual(700)
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->not->toContain('sk-ant-AAAA')
        ->and($errors[0])->not->toContain("\x1b")
        ->and(mb_strlen($errors[0]))->toBeLessThanOrEqual(700);
});

test('a short 4xx JSON body keeps its API message, redacted', function () {
    [$agent, $events] = redactionHttpAgent(new MockResponse('{"error":{"message":"bad key sk-ant-AAAAAAAAAAAAAAAAAAAAAAAA\u001b[31m"}}', ['http_code' => 401]));

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->content)->toContain("\n\nAPI response: bad key [redacted] [31m")
        ->and($output->content)->toContain('401');
});

test('a non-JSON error body is not echoed', function () {
    [$agent, $events] = redactionHttpAgent(new MockResponse('<html>internal trace: password=hunter2 at /srv/app.php</html>', ['http_code' => 400]));

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->content)->toStartWith('Provider error: ')
        ->and($output->content)->not->toContain('API response')
        ->and($output->content)->not->toContain('hunter2')
        ->and($output->content)->not->toContain('/srv/app.php');
});

test('a JSON body whose message is not a string is not echoed', function () {
    [$agent] = redactionHttpAgent(new MockResponse('{"error":{"message":{"token":"x"}},"debug":"token=leak-123"}', ['http_code' => 400]));

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->content)->not->toContain('API response')
        ->and($output->content)->not->toContain('leak-123');
});

test('a provider exception message is redacted and bounded', function () {
    $provider = new RedactionScriptedProvider([new RuntimeException("connect failed: api_key=sk-AAAAAAAAAAAAAAAAAAAA \x1b[2J" . str_repeat(' z', 1000))]);
    $agent = new RedactionTestAgent(provider: $provider);
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->content)->toStartWith('Provider error: connect failed: api_key=[redacted]')
        ->and($output->content)->not->toContain("\x1b")
        ->and(mb_strlen($output->content))->toBe(mb_strlen('Provider error: ') + 301)
        ->and($events->dataFor('agent.error')[0])->not->toContain('sk-AAAA');
});

test('a tool exception message is redacted and bounded', function () {
    $tool = new Tool('leaky', 'Throws.', [], function (array $input): ToolResult {
        throw new RuntimeException("query failed: password=hunter2 \x1b[31m" . str_repeat(' w', 1000));
    });
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'leaky', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider);
    $agent->extraTools = [$tool];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $direct = $tool->execute([]);
    $output = $agent->run(new UserMessage('Hi'));

    expect($direct->content)->toStartWith('query failed: password=[redacted] [31m')
        ->and(mb_strlen($direct->content))->toBe(501);
    foreach (redactionExposedErrors($output, $events) as $text) {
        expect($text)->not->toContain('hunter2')->and($text)->not->toContain("\x1b")->and(mb_strlen($text))->toBeLessThanOrEqual(501);
    }
});

test('an exception thrown past the tool is redacted in the result and agent.tool_error', function () {
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'boom', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider);
    $agent->extraTools = [new RedactionThrowingTool()];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));
    $texts = redactionExposedErrors($output, $events);

    expect($events->dataFor('agent.tool_error'))->toHaveCount(1)
        ->and($output->toolResults[0]->content)->toStartWith('db down: Bearer [redacted]')
        ->and(mb_strlen($output->toolResults[0]->content))->toBe(501);
    foreach ($texts as $text) {
        expect($text)->not->toContain('abcdefgh12345678');
    }
});

test('a returned error result with a secret is redacted, success content is not rewritten', function () {
    $bad = new Tool('bad', 'Errors.', [], fn(array $i): ToolResult => ToolResult::error("denied for token=tool-secret-77\n\nretry later"));
    $good = new Tool('good', 'Succeeds.', [], fn(array $i): ToolResult => ToolResult::success("line one\n\nhere is token=user-data-ok"));
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'bad', []), new ToolCall('c2', 'good', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider);
    $agent->extraTools = [$bad, $good];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->toolResults[0]->content)->toBe('denied for token=[redacted] retry later')
        ->and($output->toolResults[0]->status)->toBe(ToolResultStatus::Error)
        ->and($output->toolResults[0]->callId)->toBe('c1')
        ->and($output->toolResults[1]->content)->toBe("line one\n\nhere is token=user-data-ok");
    foreach (redactionExposedErrors($output, $events) as $text) {
        expect($text)->not->toContain('tool-secret-77');
    }
});

test('batch executor error results are redacted', function () {
    $a = new Tool('a', 'A.', [], fn(array $i): ToolResult => ToolResult::success('a'));
    $b = new Tool('b', 'B.', [], fn(array $i): ToolResult => ToolResult::success('b'));
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'a', []), new ToolCall('c2', 'b', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider, toolExecutor: new RedactionBatchExecutor());
    $agent->extraTools = [$a, $b];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->toolResults)->toHaveCount(2)
        ->and($output->toolResults[0]->content)->toBe('worker died: token=[redacted] [0m')
        ->and($output->toolResults[1]->callId)->toBe('c2');
    foreach (redactionExposedErrors($output, $events) as $text) {
        expect($text)->not->toContain('batch-secret-1')->and($text)->not->toContain("\x1b");
    }
});

test('policy denial text is redacted', function () {
    $t = new Tool('t', 'T.', [], fn(array $i): ToolResult => ToolResult::success('t'));
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 't', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider, executionPolicy: new RedactionLeakyPolicy());
    $agent->extraTools = [$t];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->toolResults[0]->content)->toBe('Denied by policy: rule matched password=[redacted]');
    foreach (redactionExposedErrors($output, $events) as $text) {
        expect($text)->not->toContain('policy-pass-9');
    }
});

test('string metadata on an error result is redacted and bounded', function () {
    $schemaTool = new \CarmeloSantana\PHPAgents\Tool\SchemaTool('remote', 'Remote.', ['type' => 'object'], function (array $input): never {
        throw new RuntimeException('POST https://x.test/mcp?api_key=meta-secret-5 failed' . str_repeat(' pad', 400));
    });
    $nested = new Tool('nested', 'Nested.', [], fn(array $i): ToolResult => ToolResult::error('nope')->withMetadata([
        'detail' => ['inner' => "token=deep-secret-6 \x1b[1m", 'count' => 3],
    ]));
    $ok = new Tool('ok', 'OK.', [], fn(array $i): ToolResult => ToolResult::success('fine')->withMetadata(['raw' => 'token=kept-on-success']));
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'remote', []), new ToolCall('c2', 'nested', []), new ToolCall('c3', 'ok', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider);
    $agent->extraTools = [$schemaTool, $nested, $ok];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));
    $results = array_merge($output->toolResults, $events->dataFor('agent.tool_result'));

    expect($output->toolResults[0]->metadata['message'])->toStartWith('POST https://x.test/mcp?api_key=[redacted] failed')
        ->and(mb_strlen($output->toolResults[0]->metadata['message']))->toBe(501)
        ->and($output->toolResults[0]->metadata['exception'])->toBe(RuntimeException::class)
        ->and($output->toolResults[1]->metadata['detail'])->toBe(['inner' => 'token=[redacted] [1m', 'count' => 3])
        ->and($output->toolResults[2]->metadata['raw'])->toBe('token=kept-on-success');
    foreach ($results as $r) {
        expect(json_encode($r->metadata))->not->toContain('meta-secret-5')->not->toContain('deep-secret-6');
    }
});

final class RedactionTimeoutBatchExecutor implements BatchToolExecutorInterface
{
    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        return $tool->execute($arguments);
    }

    public function executeBatch(array $batch): array
    {
        return array_map(
            fn(array $entry): ToolResult => new ToolResult(
                ToolResultStatus::Timeout,
                "timed out calling https://x.test/?api_key=timeout-secret-8 \x1b[0m",
                metadata: ['url' => 'token=timeout-meta-9'],
                errorCode: 'token=timeout-code-10',
            ),
            $batch,
        );
    }
}

final class RedactionLeakyJson implements JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['inner' => 'password=json-secret-12'];
    }
}

final class RedactionThrowingJson implements JsonSerializable
{
    public function jsonSerialize(): never
    {
        throw new RuntimeException('token=throwing-json-secret-15');
    }
}

final class RedactionLeakyObject
{
    public string $note = 'token=plain-object-secret-13';
}

test('a timeout result is redacted like an error result', function () {
    $a = new Tool('a', 'A.', [], fn(array $i): ToolResult => ToolResult::success('a'));
    $b = new Tool('b', 'B.', [], fn(array $i): ToolResult => ToolResult::success('b'));
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'a', []), new ToolCall('c2', 'b', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider, toolExecutor: new RedactionTimeoutBatchExecutor());
    $agent->extraTools = [$a, $b];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->toolResults)->toHaveCount(2)
        ->and($output->toolResults[0]->status)->toBe(ToolResultStatus::Timeout)
        ->and($output->toolResults[0]->content)->toBe('timed out calling https://x.test/?api_key=[redacted] [0m')
        ->and($output->toolResults[0]->metadata)->toBe(['url' => 'token=[redacted]'])
        ->and($output->toolResults[0]->errorCode)->toBe('token=[redacted]')
        ->and($output->toolResults[1]->callId)->toBe('c2');
    foreach (redactionExposedErrors($output, $events) as $text) {
        expect($text)->not->toContain('timeout-secret-8')->and($text)->not->toContain("\x1b");
    }
    foreach (array_merge($output->toolResults, $events->dataFor('agent.tool_result')) as $r) {
        expect(json_encode($r->metadata) . $r->errorCode)->not->toContain('timeout-meta-9')->not->toContain('timeout-code-10');
    }
});

test('error metadata keys, object values and the error code are redacted', function () {
    $std = new stdClass();
    $std->detail = 'token=std-secret-11';
    $tool = new Tool('leaky', 'Leaks.', [], fn(array $i): ToolResult => new ToolResult(
        ToolResultStatus::Error,
        'nope',
        metadata: [
            'password=key-secret-10' => 'value',
            'std' => $std,
            'json' => new RedactionLeakyJson(),
            'plain' => new RedactionLeakyObject(),
            'count' => 3,
        ],
        errorCode: 'E_AUTH api_key=code-secret-14 ' . str_repeat('x', 200),
    ));
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'leaky', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider);
    $agent->extraTools = [$tool];
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));
    $result = $output->toolResults[0];

    expect($result->metadata)->toBe([
        'password=[redacted]' => 'value',
        'std' => ['detail' => 'token=[redacted]'],
        'json' => ['inner' => 'password=[redacted]'],
        'plain' => ['note' => 'token=[redacted]'],
        'count' => 3,
    ])
        ->and($result->errorCode)->toStartWith('E_AUTH api_key=[redacted]')
        ->and(mb_strlen((string) $result->errorCode))->toBe(65);
    foreach (array_merge($output->toolResults, $events->dataFor('agent.tool_result')) as $r) {
        expect(serialize($r->metadata) . $r->errorCode)
            ->not->toContain('key-secret-10')->not->toContain('std-secret-11')->not->toContain('json-secret-12')
            ->not->toContain('plain-object-secret-13')->not->toContain('code-secret-14');
    }
});

test('error metadata whose jsonSerialize throws becomes null and the run completes', function () {
    $tool = new Tool('leaky', 'Leaks.', [], fn(array $i): ToolResult => new ToolResult(
        ToolResultStatus::Error,
        'nope',
        metadata: ['bad' => new RedactionThrowingJson(), 'count' => 3],
    ));
    $provider = new RedactionScriptedProvider([redactionToolRound([new ToolCall('c1', 'leaky', [])]), redactionTextRound()]);
    $agent = new RedactionTestAgent(provider: $provider);
    $agent->extraTools = [$tool];

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->toolResults[0]->metadata)->toBe(['bad' => null, 'count' => 3])
        ->and(serialize($output->toolResults[0]->metadata))->not->toContain('throwing-json-secret-15');
});

test('a provider exception message with a credential split by a control or bidi character is redacted', function () {
    $provider = new RedactionScriptedProvider([new RuntimeException("denied: Bearer\u{202E}abcdefghijkl, key sk-AAAAAAAAAAAAAAA\u{200B}BBBBBBBBB, alt sk-CCCCCCCC\eDDDDDDDDDDDD")]);
    $agent = new RedactionTestAgent(provider: $provider);
    $events = new RedactionEventRecorder();
    $agent->attach($events);

    $output = $agent->run(new UserMessage('Hi'));

    foreach ([$output->content, $events->dataFor('agent.error')[0]] as $text) {
        expect($text)->toContain('[redacted]')
            ->and($text)->not->toContain('abcdefghijkl')
            ->and($text)->not->toContain('AAAAAAAAAAAAAAA')
            ->and($text)->not->toContain('BBBBBBBBB')
            ->and($text)->not->toContain('DDDDDDDDDDDD');
    }
});

test('a provider exception message with a key glued to a word character is redacted', function () {
    $provider = new RedactionScriptedProvider([new RuntimeException('denied: reason_sk-' . str_repeat('A1b2', 6) . ' x_AIza' . str_repeat('B', 35))]);
    $agent = new RedactionTestAgent(provider: $provider);

    $output = $agent->run(new UserMessage('Hi'));

    expect($output->content)->toBe('Provider error: denied: reason_[redacted] x_[redacted]');
});
