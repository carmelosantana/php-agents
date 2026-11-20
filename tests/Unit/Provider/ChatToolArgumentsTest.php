<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// chat() applies the streaming rule to tool arguments: empty arguments are {}, arguments that
// are not a JSON object drop the call, and a response that would finish ToolUse finishes Error.
// Any other finish reason is kept.

/**
 * A provider of $class answering chat() with $body.
 *
 * @param class-string<ProviderInterface> $class
 * @param array<string, mixed> $body
 */
function chatProvider(string $class, array $body): ProviderInterface
{
    $client = new MockHttpClient([new MockResponse((string) json_encode($body), ['http_code' => 200])]);

    return new $class(model: 'test-model', apiKey: 'test-key', httpClient: $client);
}

/**
 * A chat body for $class with two calls (arguments $bad, then a valid empty object) and the
 * provider's own spelling of a tool stop, or of a max-tokens stop when $tool is false.
 *
 * @return array<string, mixed>
 */
function chatBody(string $class, mixed $bad, bool $tool = true): array
{
    return match ($class) {
        OpenAICompatibleProvider::class => [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                    ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => $bad]],
                    ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'now', 'arguments' => '']],
                ]],
                'finish_reason' => $tool ? 'tool_calls' : 'length',
            ]],
        ],
        OpenAIResponsesProvider::class => [
            'status' => $tool ? 'completed' : 'incomplete',
            'incomplete_details' => $tool ? null : ['reason' => 'max_output_tokens'],
            'output' => [
                ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'lookup', 'arguments' => $bad],
                ['type' => 'function_call', 'call_id' => 'c2', 'name' => 'now', 'arguments' => ''],
            ],
        ],
        AnthropicProvider::class => [
            'content' => [
                ['type' => 'tool_use', 'id' => 'c1', 'name' => 'lookup', 'input' => $bad],
                ['type' => 'tool_use', 'id' => 'c2', 'name' => 'now', 'input' => new stdClass()],
            ],
            'stop_reason' => $tool ? 'tool_use' : 'max_tokens',
        ],
        GeminiProvider::class => [
            'candidates' => [[
                'content' => ['parts' => [
                    ['functionCall' => ['name' => 'lookup', 'args' => $bad]],
                    ['functionCall' => ['name' => 'now', 'args' => new stdClass()]],
                ]],
                'finishReason' => $tool ? 'STOP' : 'MAX_TOKENS',
            ]],
        ],
    };
}

$providers = [
    'OpenAI-compatible' => [OpenAICompatibleProvider::class, '{"q":', 'c2'],
    'Responses' => [OpenAIResponsesProvider::class, '[1]', 'c2'],
    'Anthropic' => [AnthropicProvider::class, [1, 2], 'c2'],
    'Gemini' => [GeminiProvider::class, [1, 2], 'g00000001'],
];

test('chat() drops a call whose arguments are not a JSON object and ends a tool stop as Error', function (string $class, mixed $bad, string $keptId) {
    $response = chatProvider($class, chatBody($class, $bad))->chat([new UserMessage('Hi')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($response->toolCalls)->toEqual([new ToolCall($keptId, 'now', [])]);
})->with($providers);

test('chat() keeps a max-tokens stop when it drops a call', function (string $class, mixed $bad) {
    $response = chatProvider($class, chatBody($class, $bad, tool: false))->chat([new UserMessage('Hi')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::MaxTokens)
        ->and(array_map(fn(ToolCall $c): string => $c->name, $response->toolCalls))->not->toContain('lookup');
})->with($providers);

test('chat() reads empty arguments as an empty object and keeps a tool stop', function (string $class, mixed $bad, string $keptId) {
    $response = chatProvider($class, chatBody($class, ''))->chat([new UserMessage('Hi')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and(array_map(fn(ToolCall $c): array => $c->arguments, $response->toolCalls))->toBe([[], []]);
})->with($providers);

test('chat() drops a call over the tool-argument caps of the provider\'s request limits', function (string $class, mixed $bad, string $keptId, RequestLimits $limits) {
    $provider = chatProvider($class, chatBody($class, $bad));
    assert($provider instanceof \CarmeloSantana\PHPAgents\Provider\AbstractProvider);
    $response = $provider->withRequestLimits($limits)->chat([new UserMessage('Hi')]);

    expect($response->finishReason)->toBe(ProviderFinishReason::Error)
        ->and($response->toolCalls)->toEqual([new ToolCall($keptId, 'now', [])]);
})->with([
    'OpenAI-compatible, bytes' => [OpenAICompatibleProvider::class, '{"q":"' . str_repeat('x', 40) . '"}', 'c2', new RequestLimits(maxToolArgumentBytes: 32)],
    'Responses, bytes' => [OpenAIResponsesProvider::class, '{"q":"' . str_repeat('x', 40) . '"}', 'c2', new RequestLimits(maxToolArgumentBytes: 32)],
    'Anthropic, bytes' => [AnthropicProvider::class, ['q' => str_repeat('x', 40)], 'c2', new RequestLimits(maxToolArgumentBytes: 32)],
    'Gemini, bytes' => [GeminiProvider::class, ['q' => str_repeat('x', 40)], 'g00000001', new RequestLimits(maxToolArgumentBytes: 32)],
    'OpenAI-compatible, depth' => [OpenAICompatibleProvider::class, '{"q":{"r":{}}}', 'c2', new RequestLimits(maxToolArgumentDepth: 2)],
    'Anthropic, depth' => [AnthropicProvider::class, ['q' => ['r' => [1]]], 'c2', new RequestLimits(maxToolArgumentDepth: 2)],
]);
