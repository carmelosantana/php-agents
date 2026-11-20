<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaServerProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SSE body of `data:` lines, each optionally preceded by an `event:` line naming its type.
 *
 * @param list<array<string, mixed>|string> $events
 */
function shapeSseBody(array $events, bool $named = false): string
{
    $body = '';
    foreach ($events as $event) {
        if ($named && is_array($event)) {
            $body .= 'event: ' . $event['type'] . "\n";
        }
        $body .= 'data: ' . (is_string($event) ? $event : json_encode($event)) . "\n\n";
    }

    return $body;
}

/**
 * Reasoning "Hmm." then text "Call" + "ing." then one get_weather call, in the OpenAI chat-completions wire format
 * with usage on its own chunk after the finish chunk.
 */
function shapeOpenAIBody(): string
{
    return shapeSseBody([
        ['model' => 'm-1', 'choices' => [['delta' => ['reasoning_content' => 'Hmm.'], 'finish_reason' => null]]],
        ['model' => 'm-1', 'choices' => [['delta' => ['content' => 'Call'], 'finish_reason' => null]]],
        ['model' => 'm-1', 'choices' => [['delta' => ['content' => 'ing.'], 'finish_reason' => null]]],
        ['model' => 'm-1', 'choices' => [['delta' => ['tool_calls' => [
            ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"NYC"}']],
        ]], 'finish_reason' => null]]],
        ['model' => 'm-1', 'choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ['model' => 'm-1', 'choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7]],
        '[DONE]',
    ]);
}

/**
 * @return array{0: Closure(HttpClientInterface): AbstractProvider, 1: string}
 */
function shapeFixture(string $name): array
{
    return match ($name) {
        'anthropic' => [
            fn(HttpClientInterface $c) => new AnthropicProvider(apiKey: 'key', httpClient: $c),
            shapeSseBody([
                ['type' => 'message_start', 'message' => ['model' => 'm-1', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Hmm.']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Call']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'ing.']],
                ['type' => 'content_block_stop', 'index' => 1],
                ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'get_weather', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city":"NYC"}']],
                ['type' => 'content_block_stop', 'index' => 2],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 2]],
                ['type' => 'message_stop'],
            ], named: true),
        ],
        'openai-compatible' => [
            fn(HttpClientInterface $c) => new OpenAICompatibleProvider(model: 'm-1', apiKey: 'key', httpClient: $c),
            shapeOpenAIBody(),
        ],
        'mistral' => [
            fn(HttpClientInterface $c) => new MistralProvider(apiKey: 'key', httpClient: $c),
            shapeOpenAIBody(),
        ],
        'ollama' => [
            fn(HttpClientInterface $c) => new OllamaProvider(httpClient: $c),
            shapeOpenAIBody(),
        ],
        'xai' => [
            fn(HttpClientInterface $c) => new XAIProvider(model: 'm-1', apiKey: 'key', httpClient: $c),
            shapeOpenAIBody(),
        ],
        'llama-server' => [
            fn(HttpClientInterface $c) => new LlamaServerProvider(model: 'm-1', httpClient: $c),
            shapeOpenAIBody(),
        ],
        'openai-responses' => [
            fn(HttpClientInterface $c) => new OpenAIResponsesProvider(model: 'm-1', apiKey: 'key', httpClient: $c),
            shapeSseBody([
                ['type' => 'response.created', 'response' => ['model' => 'm-1', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
                ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Hmm.'],
                ['type' => 'response.output_text.delta', 'delta' => 'Call'],
                ['type' => 'response.output_text.delta', 'delta' => 'ing.'],
                ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '']],
                ['type' => 'response.function_call_arguments.delta', 'output_index' => 1, 'delta' => '{"city":"NYC"}'],
                ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":"NYC"}']],
                ['type' => 'response.completed', 'response' => ['model' => 'm-1', 'status' => 'completed', 'usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7]]],
            ]),
        ],
        'gemini' => [
            fn(HttpClientInterface $c) => new GeminiProvider(model: 'm-1', apiKey: 'key', httpClient: $c),
            shapeSseBody([
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Hmm.', 'thought' => true]]]]]],
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Call']]]]]],
                ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ing.'], ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'NYC']]]]]]]],
                ['candidates' => [['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7]],
            ]),
        ],
    };
}

test('stream yields one chunk per reasoning or text delta then exactly one terminal chunk with reason, usage and tool calls', function (string $name) {
    [$make, $body] = shapeFixture($name);
    $provider = $make(new MockHttpClient([new MockResponse($body, ['http_code' => 200])]));

    $chunks = array_values(iterator_to_array($provider->stream([new UserMessage('hi')]), false));

    expect($chunks)->toHaveCount(4);

    foreach (array_slice($chunks, 0, 3) as $delta) {
        expect($delta->finishReason)->toBe(ProviderFinishReason::Stop)
            ->and($delta->usage)->toBeNull()
            ->and($delta->toolCalls)->toBe([]);
    }

    $terminal = $chunks[3];

    expect($chunks[0]->reasoning)->toBe('Hmm.')
        ->and($chunks[0]->content)->toBe('')
        ->and($chunks[1]->content . $chunks[2]->content)->toBe('Calling.')
        ->and($terminal->content)->toBe('')
        ->and($terminal->model)->toBe('m-1')
        ->and($terminal->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($terminal->usage?->promptTokens)->toBe(5)
        ->and($terminal->usage?->completionTokens)->toBe(2)
        ->and($terminal->usage?->totalTokens)->toBe(7)
        ->and($terminal->toolCalls)->toHaveCount(1)
        ->and($terminal->toolCalls[0]->name)->toBe('get_weather')
        ->and($terminal->toolCalls[0]->arguments)->toBe(['city' => 'NYC']);
})->with(['anthropic', 'openai-compatible', 'mistral', 'ollama', 'xai', 'llama-server', 'openai-responses', 'gemini']);

test('AbstractProvider::stream() is final and streamEvents() is abstract', function () {
    expect((new ReflectionMethod(AbstractProvider::class, 'stream'))->isFinal())->toBeTrue()
        ->and((new ReflectionMethod(AbstractProvider::class, 'streamEvents'))->isAbstract())->toBeTrue();
});
