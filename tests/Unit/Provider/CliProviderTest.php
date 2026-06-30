<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Cli\ClaudeCliVendorAdapter;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessChunk;
use CarmeloSantana\PHPAgents\Provider\Cli\CliProcessResult;
use CarmeloSantana\PHPAgents\Provider\CliProvider;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Tests\Support\Runtime\FakeCliRuntime;

function makeCliProvider(FakeCliRuntime $runtime, string $model = 'sonnet'): CliProvider
{
    return new CliProvider($model, new ClaudeCliVendorAdapter(), $runtime);
}

test('chat builds raw-LLM argv and parses the SDK result json', function () {
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(
        exitCode: 0,
        stdout: json_encode([
            'result' => 'Hello from Claude',
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 5],
        ]),
    ));

    $provider = makeCliProvider($runtime);

    $response = $provider->chat([
        new SystemMessage('You are terse.'),
        new UserMessage('Hi'),
    ]);

    $args = $runtime->lastRequest->arguments;

    expect($args)->toContain('-p')
        ->and($args)->toContain('--tools')
        ->and($args)->toContain('--strict-mcp-config')
        ->and($args)->toContain('--bare')
        ->and($args)->toContain('--no-session-persistence');

    // --model carries the configured model id
    $modelIdx = array_search('--model', $args, true);
    expect($args[$modelIdx + 1])->toBe('sonnet');

    // system message is hoisted to --system-prompt, not the stdin transcript
    $sysIdx = array_search('--system-prompt', $args, true);
    expect($args[$sysIdx + 1])->toBe('You are terse.')
        ->and($runtime->lastRequest->stdin)->toContain('User: Hi')
        ->and($runtime->lastRequest->stdin)->not->toContain('terse');

    expect($response->content)->toBe('Hello from Claude')
        ->and($response->model)->toBe('claude-sonnet-4-6')
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($response->usage->promptTokens)->toBe(12)
        ->and($response->usage->completionTokens)->toBe(5)
        ->and($response->usage->totalTokens)->toBe(17);
});

test('cache tokens are folded into prompt tokens', function () {
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(0, json_encode([
        'result' => 'ok',
        'usage' => [
            'input_tokens' => 10,
            'cache_read_input_tokens' => 90,
            'cache_creation_input_tokens' => 5,
            'output_tokens' => 3,
        ],
    ])));

    $response = makeCliProvider($runtime)->chat([new UserMessage('x')]);

    expect($response->usage->promptTokens)->toBe(105)
        ->and($response->usage->completionTokens)->toBe(3);
});

test('tool-call and tool-result history is flattened into the prompt', function () {
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(0, json_encode(['result' => 'done'])));

    makeCliProvider($runtime)->chat([
        new UserMessage('weather?'),
        new AssistantMessage('let me check', [new ToolCall('call_1', 'lookup_weather', ['city' => 'Miami'])]),
        new ToolResultMessage(ToolResult::success('Sunny')->withCallId('call_1')),
    ]);

    $stdin = $runtime->lastRequest->stdin;

    expect($stdin)->toContain('User: weather?')
        ->and($stdin)->toContain('lookup_weather')
        ->and($stdin)->toContain('Miami')
        ->and($stdin)->toContain('Sunny');
});

test('stream parses the real CLI NDJSON envelope (system/stream_event/assistant/result) without double-counting', function () {
    // Mirrors the actual `claude --output-format stream-json --include-partial-messages`
    // wire format: init metadata, partial deltas, a full assistant message that
    // restates the text, then a terminal result that restates it again + usage.
    $events = [
        json_encode(['type' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-4-6']),
        json_encode(['type' => 'stream_event', 'event' => ['delta' => ['type' => 'text_delta', 'text' => 'Hel']]]),
        json_encode(['type' => 'stream_event', 'event' => ['delta' => ['type' => 'text_delta', 'text' => 'lo']]]),
        json_encode(['type' => 'assistant', 'message' => [
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Hello']],
        ]]),
        json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Hello', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 4, 'output_tokens' => 2]]),
    ];
    $ndjson = implode("\n", $events) . "\n";

    // Split mid-line to exercise the buffer carry between chunks.
    $half = (int) (strlen($ndjson) / 2);
    $runtime = (new FakeCliRuntime())->withChunks([
        new CliProcessChunk(content: substr($ndjson, 0, $half)),
        new CliProcessChunk(content: substr($ndjson, $half), isLast: true),
    ]);

    $provider = makeCliProvider($runtime);

    $content = '';
    $usage = null;
    foreach ($provider->stream([new UserMessage('hi')]) as $response) {
        $content .= $response->content;
        $usage = $response->usage ?? $usage;
    }

    // 'Hello' once — not 'HelloHelloHello' from delta + assistant + result.
    expect($content)->toBe('Hello')
        ->and($usage)->not->toBeNull()
        ->and($usage->completionTokens)->toBe(2);

    expect($runtime->lastRequest->arguments)->toContain('stream-json');
});

test('stream falls back to the assistant full message when no partial deltas arrive', function () {
    // Without --include-partial-messages there are no stream_event lines; the
    // assistant event is the only source of text and must not be dropped.
    $events = [
        json_encode(['type' => 'system', 'subtype' => 'init']),
        json_encode(['type' => 'assistant', 'message' => [
            'model' => 'claude-opus-4-8',
            'content' => [['type' => 'text', 'text' => 'Full answer.']],
        ]]),
        json_encode(['type' => 'result', 'is_error' => false, 'result' => 'Full answer.', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 6, 'output_tokens' => 3]]),
    ];
    $ndjson = implode("\n", $events) . "\n";

    $runtime = (new FakeCliRuntime())->withChunks([new CliProcessChunk(content: $ndjson, isLast: true)]);

    $content = '';
    foreach (makeCliProvider($runtime)->stream([new UserMessage('hi')]) as $response) {
        $content .= $response->content;
    }

    expect($content)->toBe('Full answer.');
});

test('stream surfaces a CLI is_error result as an exception', function () {
    $events = [
        json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Not logged in']]]]),
        json_encode(['type' => 'result', 'is_error' => true, 'result' => 'Not logged in · Please run /login']),
    ];
    $runtime = (new FakeCliRuntime())->withChunks([
        new CliProcessChunk(content: implode("\n", $events) . "\n", isLast: true),
    ]);

    expect(function () use ($runtime) {
        foreach (makeCliProvider($runtime)->stream([new UserMessage('hi')]) as $_) {
            // drain
        }
    })->toThrow(RuntimeException::class, 'Not logged in');
});

test('non-zero exit raises a descriptive error', function () {
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(1, '', 'not logged in'));

    expect(fn() => makeCliProvider($runtime)->chat([new UserMessage('hi')]))
        ->toThrow(RuntimeException::class, 'not logged in');
});

test('structured is_error json surfaces the CLI message', function () {
    // The CLI exits non-zero but emits a structured error (e.g. not logged in).
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(1, json_encode([
        'type' => 'result',
        'is_error' => true,
        'result' => 'Not logged in · Please run /login',
    ])));

    expect(fn() => makeCliProvider($runtime)->chat([new UserMessage('hi')]))
        ->toThrow(RuntimeException::class, 'Not logged in');
});

test('withModel swaps the model used in argv', function () {
    $runtime = (new FakeCliRuntime())->withResult(new CliProcessResult(0, json_encode(['result' => 'ok'])));

    $provider = makeCliProvider($runtime, 'sonnet')->withModel('opus');
    $provider->chat([new UserMessage('hi')]);

    $args = $runtime->lastRequest->arguments;
    $modelIdx = array_search('--model', $args, true);

    expect($provider->getModel())->toBe('opus')
        ->and($args[$modelIdx + 1])->toBe('opus');
});

test('isAvailable and models delegate to runtime and adapter', function () {
    $runtime = (new FakeCliRuntime())->withAvailability(false);
    $provider = makeCliProvider($runtime);

    expect($provider->isAvailable())->toBeFalse();

    $models = makeCliProvider((new FakeCliRuntime()))->models();
    expect($models)->not->toBeEmpty()
        ->and($models[0]->provider)->toBe('claude-cli');
});
