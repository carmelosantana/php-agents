<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Exception\TerminationException;
use CarmeloSantana\PHPAgents\Exception\ToolNotFoundException;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Prompt\SystemPrompt;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\DoneTool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use SplObserver;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

abstract class AbstractAgent implements AgentInterface
{
    /**
     * Default maximum iterations when no role-specific or global override is configured.
     * Use 0 to indicate unlimited iterations (maps to PHP_INT_MAX internally).
     */
    public const int DEFAULT_MAX_ITERATIONS = 25;

    /** @var SplObserver[] */
    private array $observers = [];

    /** @var ToolkitInterface[] */
    private array $toolkits = [];

    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly int $maxIter = self::DEFAULT_MAX_ITERATIONS,
        private readonly ?ToolExecutionPolicyInterface $executionPolicy = null,
        private readonly ?CancellationTokenInterface $cancellationToken = null,
        private readonly ?PendingInputProviderInterface $pendingInputProvider = null,
        private readonly ?ContextWindowInterface $contextWindow = null,
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
     * Resolve the effective iteration limit.
     *
     * A value of 0 is the sentinel for "unlimited" — mapped to PHP_INT_MAX
     * so the standard for-loop works without special-casing.
     */
    private function effectiveMaxIterations(): int
    {
        return $this->maxIter === 0 ? \PHP_INT_MAX : $this->maxIter;
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

    public function run(MessageInterface $input, ?Conversation $history = null): Output
    {
        $this->notify('agent.start', $input);

        $allTools = $this->allTools();
        $systemPrompt = $this->buildSystemPrompt($allTools);

        $conversation = new Conversation();
        $conversation->add(new SystemMessage($systemPrompt));

        // Inject prior conversation history (skip system messages — we use our own)
        if ($history !== null) {
            foreach ($history->messages() as $msg) {
                if ($msg->role() === \CarmeloSantana\PHPAgents\Enum\Role::System) {
                    continue;
                }
                $conversation->add($msg);
            }
        }

        $conversation->add($input);

        $allToolResults = [];
        $totalUsage = new Usage();

        $effectiveMax = $this->effectiveMaxIterations();

        if ($this->maxIter === 0) {
            $this->notify('agent.warning', 'Unlimited iterations enabled (max_iterations=0). The agent will run until the task is complete.');
        }

        for ($i = 0; $i < $effectiveMax; $i++) {
            // Apply context window pruning when a budget is configured.
            // This prevents unbounded conversation growth that would cause
            // provider context-length errors.
            if ($this->contextWindow !== null) {
                $budget = $this->contextWindow->availableTokens();
                if ($budget > 0) {
                    $conversation = $conversation->fitWithinBudget($budget);
                }
            }

            // Check cooperative cancellation before each iteration
            if ($this->cancellationToken?->isCancelled()) {
                $this->notify('agent.error', 'Task cancelled');

                return new Output(
                    content: 'Task was cancelled.',
                    toolResults: $allToolResults,
                    usage: $totalUsage,
                    iterations: $i + 1,
                    conversation: $conversation,
                );
            }

            // Inject any pending external input into the conversation
            if ($this->pendingInputProvider !== null) {
                foreach ($this->pendingInputProvider->consumePendingInputs() as $pendingInput) {
                    $conversation->add($pendingInput);
                }
            }

            $this->notify('agent.iteration', $i + 1);

            try {
                $content = '';
                $toolCalls = [];
                $streamUsage = null;
                $streamModel = '';

                foreach ($this->provider->stream($conversation->messages(), $allTools) as $chunk) {
                    if ($chunk->content !== '') {
                        $content .= $chunk->content;
                        $this->notify('agent.text_delta', $chunk->content);
                    }

                    if (!empty($chunk->toolCalls)) {
                        $toolCalls = array_merge($toolCalls, $chunk->toolCalls);
                    }

                    if ($chunk->usage !== null) {
                        // Merge usage across chunks — some providers split
                        // input/output tokens across separate events (e.g.
                        // Anthropic sends input in message_start, output in
                        // message_delta). Take the max of each field.
                        if ($streamUsage === null) {
                            $streamUsage = $chunk->usage;
                        } else {
                            $streamUsage = new Usage(
                                promptTokens: max($streamUsage->promptTokens, $chunk->usage->promptTokens),
                                completionTokens: max($streamUsage->completionTokens, $chunk->usage->completionTokens),
                                totalTokens: max(
                                    $streamUsage->totalTokens,
                                    $chunk->usage->totalTokens,
                                    max($streamUsage->promptTokens, $chunk->usage->promptTokens)
                                        + max($streamUsage->completionTokens, $chunk->usage->completionTokens),
                                ),
                            );
                        }
                    }

                    if ($chunk->model !== '') {
                        $streamModel = $chunk->model;
                    }
                }

                $response = new Response(
                    content: $content,
                    finishReason: !empty($toolCalls) ? FinishReason::ToolUse : FinishReason::Stop,
                    toolCalls: $toolCalls,
                    model: $streamModel,
                    usage: $streamUsage,
                );
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();

                // Extract API response body for HTTP client errors (4xx/5xx)
                // Symfony's ClientException only includes the status line, not the
                // API error body, for non-RFC-7807 APIs like Anthropic and OpenAI.
                if ($e instanceof ClientExceptionInterface) {
                    try {
                        $body = $e->getResponse()->getContent(false);
                        $decoded = json_decode($body, true);

                        // Anthropic: {"type":"error","error":{"message":"..."}}
                        // OpenAI:    {"error":{"message":"..."}}
                        $apiMessage = $decoded['error']['message']
                            ?? $decoded['message']
                            ?? $body;

                        $errorMessage .= "\n\nAPI response: " . $apiMessage;
                    } catch (\Throwable) {
                        // If we can't read the body, fall through with original message
                    }
                }

                $this->notify('agent.error', $errorMessage);

                return new Output(
                    content: 'Provider error: ' . $errorMessage,
                    toolResults: $allToolResults,
                    usage: $totalUsage,
                    iterations: $i + 1,
                    conversation: $conversation,
                );
            }

            if ($response->usage !== null) {
                $totalUsage = new Usage(
                    promptTokens: $totalUsage->promptTokens + $response->usage->promptTokens,
                    completionTokens: $totalUsage->completionTokens + $response->usage->completionTokens,
                    totalTokens: $totalUsage->totalTokens + $response->usage->totalTokens,
                );

                // Report actual usage to context window for accurate tracking
                $this->contextWindow?->report($response->usage);
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
                        conversation: $conversation,
                    );
                }
            }

            if (!empty($response->toolCalls)) {
                $conversation->add(new AssistantMessage($response->content, $response->toolCalls));

                foreach ($response->toolCalls as $toolCall) {
                    // Check cancellation between individual tool calls so the
                    // caller can interrupt mid-iteration without waiting for
                    // all queued tools to finish. The outer loop re-checks
                    // isCancelled() and returns a graceful Output.
                    if ($this->cancellationToken?->isCancelled()) {
                        break;
                    }

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
                    } catch (TerminationException $e) {
                        // Tool requested immediate loop termination (e.g. restart)
                        $result = ToolResult::success($e->getMessage())->withCallId($toolCall->id);
                        $allToolResults[] = $result;
                        $conversation->add(new ToolResultMessage($result));
                        $this->notify('agent.tool_result', $result);

                        return new Output(
                            content: $e->getMessage(),
                            toolResults: $allToolResults,
                            usage: $totalUsage,
                            iterations: $i + 1,
                            conversation: $conversation,
                        );
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

            // Text-only response (no tool calls) — this IS the response.
            // The done tool is only needed after tool use to present results.
            if ($response->content !== '') {
                $conversation->add(new AssistantMessage($response->content));
                $this->notify('agent.done', ['response' => $response->content]);

                return new Output(
                    content: $response->content,
                    toolResults: $allToolResults,
                    usage: $totalUsage,
                    model: $response->model,
                    iterations: $i + 1,
                    conversation: $conversation,
                );
            }

            // Empty response with no tool calls — let it retry
            $conversation->add(new AssistantMessage($response->content));
        }

        $this->notify('agent.error', 'Max iterations reached');

        return new Output(
            content: 'Agent reached maximum iterations without completing.',
            toolResults: $allToolResults,
            usage: $totalUsage,
            iterations: $this->maxIterations(),
            conversation: $conversation,
        );
    }

    /**
     * Collect all tools with name-based deduplication (last-registered wins).
     *
     * Order: standalone tools → toolkit tools → DoneTool.
     * If multiple tools share the same name, the later registration
     * silently overrides the earlier one. This allows workspace-installed
     * toolkit packages to replace core tools.
     *
     * @return ToolInterface[]
     */
    private function allTools(): array
    {
        $indexed = [];

        foreach ($this->tools() as $tool) {
            $indexed[$tool->name()] = $tool;
        }

        foreach ($this->toolkits as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                $indexed[$tool->name()] = $tool;
            }
        }

        $indexed[DoneTool::NAME] = DoneTool::create();

        return array_values($indexed);
    }

    /**
     * @param ToolInterface[] $tools
     */
    private function buildSystemPrompt(array $tools): string
    {
        $prompt = SystemPrompt::withIdentity($this->instructions());
        $prompt = SystemPrompt::withTools($tools, $prompt);
        $prompt = SystemPrompt::withIterationBudget($this->maxIter, $prompt);

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
