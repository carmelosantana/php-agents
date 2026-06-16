<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * Scripted provider that yields one Response per stream() call.
 */
final class EmptyResponseScriptedProvider implements ProviderInterface
{
    /** @var list<Response> */
    private array $scripts;

    /** @var list<list<MessageInterface>> */
    public array $streamCalls = [];

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
        $this->streamCalls[] = $messages;

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

final class EmptyResponseTestAgent extends AbstractAgent
{
    public function instructions(): string
    {
        return 'You are an empty-response test agent.';
    }
}

function emptyResponse(string $reasoning = ''): Response
{
    return new Response(
        content: '',
        finishReason: ProviderFinishReason::Stop,
        model: 'test-model',
        reasoning: $reasoning,
    );
}

function lastUserMessageContent(array $messages): string
{
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if ($messages[$i]->role() === Role::User) {
            $content = $messages[$i]->content();

            return is_string($content) ? $content : '';
        }
    }

    return '';
}

test('nudge injects a corrective user message and retries', function () {
    $provider = new EmptyResponseScriptedProvider([
        emptyResponse(),
        new Response(content: 'Recovered answer.', finishReason: ProviderFinishReason::Stop, model: 'test-model'),
    ]);

    $agent = new EmptyResponseTestAgent(
        provider: $provider,
        emptyResponseHandling: EmptyResponseHandling::Nudge,
        maxEmptyResponseRetries: 2,
    );

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('Recovered answer.')
        ->and($provider->streamCalls)->toHaveCount(2)
        ->and(lastUserMessageContent($provider->streamCalls[1]))->toContain('no final answer text');
});

test('nudge exits with EmptyResponse after the retry cap', function () {
    $provider = new EmptyResponseScriptedProvider([
        emptyResponse(),
        emptyResponse(),
        emptyResponse(),
    ]);

    $agent = new EmptyResponseTestAgent(
        provider: $provider,
        emptyResponseHandling: EmptyResponseHandling::Nudge,
        maxEmptyResponseRetries: 2,
    );

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->finishReason)->toBe(AgentFinishReason::EmptyResponse)
        ->and($output->content)->toContain('empty responses')
        ->and($provider->streamCalls)->toHaveCount(3);
});

test('nudge_then_fallback returns reasoning as the answer after retries are exhausted', function () {
    $provider = new EmptyResponseScriptedProvider([
        emptyResponse(reasoning: 'First pass of thinking.'),
        emptyResponse(reasoning: "The real answer is 42.\n"),
    ]);

    $agent = new EmptyResponseTestAgent(
        provider: $provider,
        emptyResponseHandling: EmptyResponseHandling::NudgeThenFallback,
        maxEmptyResponseRetries: 1,
    );

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('The real answer is 42.')
        ->and($output->reasoning)->toBe("The real answer is 42.\n")
        ->and($provider->streamCalls)->toHaveCount(2);
});

test('nudge_then_fallback without reasoning exits with EmptyResponse', function () {
    $provider = new EmptyResponseScriptedProvider([
        emptyResponse(),
        emptyResponse(),
    ]);

    $agent = new EmptyResponseTestAgent(
        provider: $provider,
        emptyResponseHandling: EmptyResponseHandling::NudgeThenFallback,
        maxEmptyResponseRetries: 1,
    );

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->finishReason)->toBe(AgentFinishReason::EmptyResponse);
});

test('fallback returns reasoning immediately without retrying', function () {
    $provider = new EmptyResponseScriptedProvider([
        emptyResponse(reasoning: 'Reasoning-only answer.'),
    ]);

    $agent = new EmptyResponseTestAgent(
        provider: $provider,
        emptyResponseHandling: EmptyResponseHandling::Fallback,
    );

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('Reasoning-only answer.')
        ->and($provider->streamCalls)->toHaveCount(1);
});

test('ignore preserves legacy silent retry until max iterations', function () {
    $provider = new EmptyResponseScriptedProvider([
        emptyResponse(),
        emptyResponse(),
        emptyResponse(),
    ]);

    $agent = new EmptyResponseTestAgent(
        provider: $provider,
        maxIter: 3,
        emptyResponseHandling: EmptyResponseHandling::Ignore,
    );

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->finishReason)->toBe(AgentFinishReason::MaxIterations)
        ->and($provider->streamCalls)->toHaveCount(3)
        // No corrective nudges injected under Ignore
        ->and(lastUserMessageContent($provider->streamCalls[2]))->toBe('Say something.');
});

test('empty response counter resets after a successful turn', function () {
    $provider = new EmptyResponseScriptedProvider([
        emptyResponse(),
        new Response(content: 'Answer.', finishReason: ProviderFinishReason::Stop, model: 'test-model', reasoning: 'brief thought'),
    ]);

    $agent = new EmptyResponseTestAgent(
        provider: $provider,
        emptyResponseHandling: EmptyResponseHandling::Nudge,
        maxEmptyResponseRetries: 1,
    );

    $output = $agent->run(new UserMessage('Say something.'));

    expect($output->finishReason)->toBe(AgentFinishReason::Stop)
        ->and($output->content)->toBe('Answer.')
        ->and($output->reasoning)->toBe('brief thought');
});
