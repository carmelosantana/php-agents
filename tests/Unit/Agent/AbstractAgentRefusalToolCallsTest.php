<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefusalToolCallsSpyExecutor implements ToolExecutorInterface
{
    public int $calls = 0;

    public function execute(ToolInterface $tool, array $arguments): ToolResult
    {
        $this->calls++;

        return $tool->execute($arguments);
    }
}

final class RefusalToolCallsTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a refusal test agent.';
    }

    public function tools(): array
    {
        return [new Tool('ping', 'Return pong.', [], fn(array $input): ToolResult => ToolResult::success('pong'))];
    }
}

/** @param list<array<string, mixed>> $events */
function refusalToolCallsSse(array $events, bool $done = false): MockResponse
{
    $sse = implode('', array_map(fn(array $e) => 'data: ' . json_encode($e) . "\n\n", $events));

    return new MockResponse($sse . ($done ? "data: [DONE]\n\n" : ''), ['http_code' => 200]);
}

dataset('refusal streams with pending tool calls', [
    'openai responses' => [fn(): ProviderInterface => new OpenAIResponsesProvider(
        model: 'gpt-4o',
        apiKey: 'k',
        httpClient: new MockHttpClient([refusalToolCallsSse([
            ['type' => 'response.refusal.delta', 'delta' => 'I cannot help.'],
            ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'ping']],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 1, 'delta' => '{}'],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ])]),
    )],
    'openai compatible' => [fn(): ProviderInterface => new OpenAICompatibleProvider(
        model: 'gpt-4o',
        apiKey: 'k',
        httpClient: new MockHttpClient([refusalToolCallsSse([
            ['choices' => [['delta' => ['refusal' => 'I cannot help.'], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'ping', 'arguments' => '{}']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ], true)]),
    )],
]);

test('a streamed refusal with pending tool calls ends the run as Refusal and never calls the executor', function (Closure $makeProvider) {
    $executor = new RefusalToolCallsSpyExecutor();
    $agent = new RefusalToolCallsTestAgent(provider: $makeProvider(), toolExecutor: $executor);

    $output = $agent->run(new UserMessage('Hi'));

    expect($executor->calls)->toBe(0)
        ->and($output->finishReason)->toBe(AgentFinishReason::Refusal)
        ->and($output->providerFinishReason)->toBe(ProviderFinishReason::Refusal)
        ->and($output->content)->toBe('I cannot help.')
        ->and($output->toolResults)->toBe([]);
})->with('refusal streams with pending tool calls');
