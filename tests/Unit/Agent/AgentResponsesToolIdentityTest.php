<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An SSE body of Responses API events.
 *
 * @param list<array<string, mixed>> $events
 */
function identitySseBody(array $events): string
{
    return implode('', array_map(fn(array $e) => 'data: ' . json_encode($e) . "\n\n", $events));
}

/**
 * A function call whose added item lacks its call_id and name; the done item carries them.
 *
 * @return list<array<string, mixed>>
 */
function identityRecoveredCall(string $callId, string $name, string $arguments, bool $streamArguments): array
{
    return [
        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'arguments' => '']],
        ...($streamArguments ? [['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => $arguments]] : []),
        ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'function_call', 'call_id' => $callId, 'name' => $name, 'arguments' => $arguments]],
        ['type' => 'response.completed', 'response' => ['model' => 'gpt-4o-2024', 'status' => 'completed']],
    ];
}

/**
 * @param list<string> $bodies SSE bodies, one per request
 * @param list<string> $requests Filled with each request's JSON body
 */
function identityAgent(array $bodies, array &$requests): Agent
{
    $responses = array_map(fn(string $body) => new MockResponse($body, ['http_code' => 200]), $bodies);
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
        $requests[] = (string) ($options['body'] ?? '');

        return array_shift($responses) ?? throw new RuntimeException('No response left.');
    });
    $provider = new OpenAIResponsesProvider(model: 'gpt-4o', apiKey: 'key', httpClient: $client);

    return new Agent($provider, tools: [
        new Tool('echo', 'Echo text.', [new StringParameter('text', 'Text to echo')], fn(array $input): ToolResult => ToolResult::success('echo: ' . ($input['text'] ?? ''))),
    ]);
}

test('a Responses call whose id and name arrive only on the done item runs through the agent', function (bool $streamArguments) {
    $requests = [];
    $agent = identityAgent([
        identitySseBody(identityRecoveredCall('call_1', 'echo', '{"text":"hi"}', $streamArguments)),
        identitySseBody([
            ['type' => 'response.output_text.delta', 'delta' => 'Done.'],
            ['type' => 'response.completed', 'response' => ['model' => 'gpt-4o-2024', 'status' => 'completed']],
        ]),
    ], $requests);

    $output = $agent->run(new UserMessage('Echo hi.'));
    $assistant = array_values(array_filter($output->conversation->messages(), fn($m) => $m instanceof AssistantMessage && $m->toolCalls() !== []));

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('Done.')
        ->and($output->toolResults)->toHaveCount(1)
        ->and($output->toolResults[0]->content)->toBe('echo: hi')
        ->and($output->toolResults[0]->callId)->toBe('call_1')
        ->and($assistant[0]->toolCalls()[0]->id)->toBe('call_1')
        ->and($assistant[0]->toolCalls()[0]->name)->toBe('echo')
        ->and($requests[1])->toContain('"call_id":"call_1"')
        ->and($requests[1])->not->toContain('"call_id":""');
})->with(['streamed arguments' => true, 'arguments on the done item' => false]);

test('a Responses done call whose id and name arrive only on the done item finishes the run', function (bool $streamArguments) {
    $requests = [];
    $agent = identityAgent([identitySseBody(identityRecoveredCall('call_d', 'done', '{"response":"All set."}', $streamArguments))], $requests);

    $output = $agent->run(new UserMessage('Finish.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Done)
        ->and($output->content)->toBe('All set.')
        ->and($requests)->toHaveCount(1);
})->with(['streamed arguments' => true, 'arguments on the done item' => false]);
