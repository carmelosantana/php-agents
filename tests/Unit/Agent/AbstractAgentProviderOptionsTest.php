<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Scripted provider that records the $options passed to every stream() call.
 */
final class ProviderOptionsRecordingProvider implements ProviderInterface
{
    /** @var list<Response> */
    private array $scripts;

    /** @var list<array<string, mixed>> */
    public array $optionsCalls = [];

    /**
     * @param list<Response> $scripts
     */
    public function __construct(array $scripts)
    {
        $this->scripts = $scripts;
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('chat() is not used by AbstractAgent::run().');
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->optionsCalls[] = $options;

        $script = array_shift($this->scripts);
        if ($script === null) {
            throw new RuntimeException('No scripted stream response available.');
        }

        yield $script;
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        throw new LogicException('structured() is not used by these tests.');
    }

    public function models(): array
    {
        return [
            new ModelDefinition(
                id: 'test-model',
                name: 'Test Model',
                provider: 'test',
                capabilities: [ModelCapability::Text, ModelCapability::Tools],
            ),
        ];
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

final class ProviderOptionsTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are a provider-options test agent.';
    }

    public function tools(): array
    {
        return [new Tool('ping', 'Return pong.', [], fn(array $input): ToolResult => ToolResult::success('pong'))];
    }
}

/**
 * A subclass written before AbstractAgent took providerOptions, which declares the same names
 * privately. It must still load and run.
 */
final class ProviderOptionsLegacyAgent extends AbstractAgent
{
    /** @var array<string, mixed> */
    private array $providerOptions = ['legacy' => true];

    public function instructions(): string
    {
        return 'You are a legacy agent.';
    }

    /**
     * @return array<string, mixed>
     */
    private function providerOptions(): array
    {
        return $this->providerOptions;
    }

    /**
     * @return array<string, mixed>
     */
    public function legacyOptions(): array
    {
        return $this->providerOptions();
    }
}

function providerOptionsToolRound(): array
{
    return [
        new Response(
            content: '',
            finishReason: ProviderFinishReason::ToolUse,
            toolCalls: [new ToolCall('call-1', 'ping', [])],
            model: 'test-model',
        ),
        new Response(content: 'Finished.', finishReason: ProviderFinishReason::Stop, model: 'test-model'),
    ];
}

test('run passes constructor providerOptions to provider stream on every iteration', function () {
    $options = ['max_tokens' => 1234, 'thinking' => ['type' => 'adaptive']];
    $provider = new ProviderOptionsRecordingProvider(providerOptionsToolRound());

    $agent = new ProviderOptionsTestAgent(provider: $provider, providerOptions: $options);

    $output = $agent->run(new UserMessage('Ping, then answer.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('Finished.')
        ->and($provider->optionsCalls)->toBe([$options, $options]);
});

test('options default to an empty array', function () {
    $provider = new ProviderOptionsRecordingProvider(providerOptionsToolRound());

    (new ProviderOptionsTestAgent(provider: $provider))->run(new UserMessage('Ping, then answer.'));

    expect($provider->optionsCalls)->toBe([[], []]);
});

test('a legacy subclass with its own private providerOptions() method and $providerOptions property still loads and runs', function () {
    $provider = new ProviderOptionsRecordingProvider([
        new Response(content: 'Legacy answer.', finishReason: ProviderFinishReason::Stop, model: 'test-model'),
    ]);

    $agent = new ProviderOptionsLegacyAgent(provider: $provider, providerOptions: ['max_tokens' => 1234]);

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->content)->toBe('Legacy answer.')
        ->and($agent->legacyOptions())->toBe(['legacy' => true])
        ->and($provider->optionsCalls)->toBe([['max_tokens' => 1234]]);
});
