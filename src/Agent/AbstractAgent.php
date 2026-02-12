<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Exception\ToolNotFoundException;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Prompt\SystemPrompt;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\DoneTool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use SplObserver;

abstract class AbstractAgent implements AgentInterface
{
    /** @var SplObserver[] */
    private array $observers = [];

    /** @var ToolkitInterface[] */
    private array $toolkits = [];

    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly int $maxIter = 25,
        private readonly ?ToolExecutionPolicyInterface $executionPolicy = null,
    ) {}

    abstract public function instructions(): string;

    public function tools(): array
    {
        return [];
    }

    public function provider(): ProviderInterface
    {
        return $this->provider;
    }

    public function maxIterations(): int
    {
        return $this->maxIter;
    }

    /**
     * @return ModelCapability[]
     */
    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools];
    }

    public function addToolkit(ToolkitInterface $toolkit): self
    {
        $this->toolkits[] = $toolkit;

        return $this;
    }

    public function run(MessageInterface $input): Output
    {
        $this->notify('agent.start', $input);

        $allTools = $this->allTools();
        $systemPrompt = $this->buildSystemPrompt($allTools);

        $conversation = new Conversation();
        $conversation->add(new SystemMessage($systemPrompt));
        $conversation->add($input);

        $allToolResults = [];
        $totalUsage = new Usage();
        $lastContent = '';

        for ($i = 0; $i < $this->maxIterations(); $i++) {
            $this->notify('agent.iteration', $i + 1);

            try {
                $response = $this->provider->chat(
                    $conversation->messages(),
                    $allTools,
                );
            } catch (\Throwable $e) {
                $this->notify('agent.error', $e->getMessage());

                return new Output(
                    content: 'Provider error: ' . $e->getMessage(),
                    toolResults: $allToolResults,
                    usage: $totalUsage,
                    iterations: $i + 1,
                );
            }

            if ($response->usage !== null) {
                $totalUsage = new Usage(
                    promptTokens: $totalUsage->promptTokens + $response->usage->promptTokens,
                    completionTokens: $totalUsage->completionTokens + $response->usage->completionTokens,
                    totalTokens: $totalUsage->totalTokens + $response->usage->totalTokens,
                );
            }

            foreach ($response->toolCalls as $toolCall) {
                if ($toolCall->name === DoneTool::NAME) {
                    $this->notify('agent.done', $toolCall->arguments);

                    return new Output(
                        content: $toolCall->arguments['response'] ?? '',
                        toolResults: $allToolResults,
                        usage: $totalUsage,
                        model: $response->model,
                        iterations: $i + 1,
                    );
                }
            }

            if (!empty($response->toolCalls)) {
                $conversation->add(new AssistantMessage($response->content, $response->toolCalls));

                foreach ($response->toolCalls as $toolCall) {
                    $this->notify('agent.tool_call', $toolCall);

                    // Check execution policy before running the tool
                    if ($this->executionPolicy !== null) {
                        $policyResult = $this->executionPolicy->shouldExecute(
                            $toolCall->name,
                            $toolCall->arguments,
                        );

                        if ($policyResult !== true) {
                            $result = ToolResult::error(
                                "Denied by policy: {$policyResult}",
                            )->withCallId($toolCall->id);
                            $allToolResults[] = $result;
                            $conversation->add(new ToolResultMessage($result));
                            $this->notify('agent.tool_result', $result);
                            continue;
                        }
                    }

                    try {
                        $tool = $this->findTool($toolCall->name, $allTools);
                        $result = $tool->execute($toolCall->arguments);
                        $result = $result->withCallId($toolCall->id);
                    } catch (\Throwable $e) {
                        $this->notify('agent.tool_error', $e->getMessage());
                        $result = ToolResult::error($e->getMessage())->withCallId($toolCall->id);
                    }

                    $allToolResults[] = $result;
                    $conversation->add(new ToolResultMessage($result));
                    $this->notify('agent.tool_result', $result);
                }

                continue;
            }

            if ($response->content === $lastContent && $response->content !== '') {
                $conversation->add(new AssistantMessage(
                    'Warning: You are repeating yourself. Please make progress or call the done tool.',
                ));
            }

            $lastContent = $response->content;
            $conversation->add(new AssistantMessage($response->content));

            if ($response->finishReason === FinishReason::Stop && $response->content !== '') {
                continue;
            }
        }

        $this->notify('agent.error', 'Max iterations reached');

        return new Output(
            content: 'Agent reached maximum iterations without completing.',
            toolResults: $allToolResults,
            usage: $totalUsage,
            iterations: $this->maxIterations(),
        );
    }

    /**
     * @return ToolInterface[]
     */
    private function allTools(): array
    {
        $tools = [...$this->tools()];

        foreach ($this->toolkits as $toolkit) {
            $tools = [...$tools, ...$toolkit->tools()];
        }

        $tools[] = new DoneTool();

        return $tools;
    }

    /**
     * @param ToolInterface[] $tools
     */
    private function buildSystemPrompt(array $tools): string
    {
        $prompt = SystemPrompt::withIdentity($this->instructions());
        $prompt = SystemPrompt::withTools($tools, $prompt);

        if (!empty($this->toolkits)) {
            $prompt = SystemPrompt::withToolkits($this->toolkits, $prompt);
        }

        return SystemPrompt::render($prompt);
    }

    /**
     * @param ToolInterface[] $tools
     */
    private function findTool(string $name, array $tools): ToolInterface
    {
        foreach ($tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        throw ToolNotFoundException::forName($name);
    }

    public function attach(SplObserver $observer): void
    {
        $this->observers[] = $observer;
    }

    public function detach(SplObserver $observer): void
    {
        $this->observers = array_filter(
            $this->observers,
            fn($o) => $o !== $observer,
        );
    }

    public function notify(string $event = '', mixed $data = null): void
    {
        foreach ($this->observers as $observer) {
            $this->lastEvent = $event;
            $this->lastEventData = $data;
            $observer->update($this);
        }
    }

    public function lastEvent(): string
    {
        return $this->lastEvent;
    }

    public function lastEventData(): mixed
    {
        return $this->lastEventData;
    }

    private string $lastEvent = '';
    private mixed $lastEventData = null;
}
