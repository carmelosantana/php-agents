<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\BudgetPruningStrategyInterface;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\TerminationException;
use CarmeloSantana\PHPAgents\Exception\ToolNotFoundException;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Prompt\SystemPrompt;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\DoneTool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
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

    /**
     * Maximum number of tools exposed to the provider per iteration.
     * 0 = unlimited (default). When set, DoneTool always counts toward the cap.
     * Use setMaxTools() to configure — e.g. from OrchestratorAgent based on config.
     */
    protected int $maxTools = 0;

    private readonly ToolExecutorInterface $toolExecutor;

    private readonly TickCallbackInterface $tickCallback;

    public function setMaxTools(int $max): void
    {
        $this->maxTools = $max;
    }

    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly int $maxIter = self::DEFAULT_MAX_ITERATIONS,
        private readonly ?ToolExecutionPolicyInterface $executionPolicy = null,
        private readonly ?CancellationTokenInterface $cancellationToken = null,
        private readonly ?PendingInputProviderInterface $pendingInputProvider = null,
        private readonly ?ContextWindowInterface $contextWindow = null,
        private readonly ?BudgetPruningStrategyInterface $pruningStrategy = null,
        private readonly int $safetyMarginPercent = 20,
        private readonly float $budgetExitThreshold = 0.0,
        private readonly int $budgetExitWrapUpIterations = 2,
        ?ToolExecutorInterface $toolExecutor = null,
        ?TickCallbackInterface $tickCallback = null,
        private readonly EmptyResponseHandling $emptyResponseHandling = EmptyResponseHandling::Nudge,
        private readonly int $maxEmptyResponseRetries = 2,
    ) {
        $this->toolExecutor = $toolExecutor ?? new SynchronousToolExecutor();
        $this->tickCallback = $tickCallback ?? new NullTickCallback();
    }

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

    public function addToolkit(ToolkitInterface $toolkit): static
    {
        $this->toolkits[] = $toolkit;

        return $this;
    }

    public function run(MessageInterface $input, ?Conversation $history = null): Output
    {
        $this->notify('agent.start', $input);

        // Advertised tools are capped by maxTools and sent to the provider.
        // Executable tools are the full uncapped set — used by findTool() so
        // that tools discovered via tool_search can still be executed even
        // when not in the provider's tool schema payload.
        // executableToolIndex is name-keyed for O(1) lookup in findTool().
        $advertisedTools = $this->allTools();
        $executableToolIndex = $this->collectAllToolsIndexed();
        $systemPrompt = $this->buildSystemPrompt($advertisedTools);

        $conversation = new Conversation();
        $conversation->add(new SystemMessage($systemPrompt));

        // Inject prior conversation history (skip system messages — we use our own).
        // Repair tool pairing before injection so broken history from interrupted
        // sessions (orphaned assistant tool_calls with no matching tool results)
        // is cleaned up before reaching any provider.
        if ($history !== null) {
            $history = $history->repairToolPairing();
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

        // Budget-based exit state
        $budgetExitTriggered = false;
        $wrapUpIterationsRemaining = $this->budgetExitWrapUpIterations;

        // Consecutive turns that produced no content and no tool calls.
        // Governs the EmptyResponseHandling policy instead of letting an
        // unresponsive model silently burn the full iteration budget.
        $consecutiveEmptyResponses = 0;

        if ($this->maxIter === 0) {
            $this->notify('agent.warning', 'Unlimited iterations enabled (max_iterations=0). The agent will run until the task is complete.');
        }

        // Seed the context window with a conversation estimate so monitoring
        // (usagePercent, isWarning, isCritical) is accurate from iteration 1.
        $this->contextWindow?->estimate($conversation->estimateTokens());

        for ($i = 0; $i < $effectiveMax; $i++) {
            // Apply context window pruning when a budget is configured.
            // Use the full input window (maxTokens - reservedTokens) as the
            // budget target. availableTokens() is unsuitable here because after
            // report(totalTokens), usedTokens reflects the last call's total
            // (prompt + completion), effectively double-counting the conversation.
            if ($this->contextWindow !== null) {
                $budget = $this->contextWindow->maxTokens() - $this->contextWindow->reservedTokens();
                if ($budget > 0) {
                    $conversation = $conversation->fitWithinBudget($budget, $this->safetyMarginPercent, $this->pruningStrategy);
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
                    finishReason: AgentFinishReason::Error,
                );
            }

            // Budget wrap-up countdown: after the threshold fires, the agent
            // gets budgetExitWrapUpIterations to call done(). If it doesn't,
            // force-exit here at the top of the next iteration.
            if ($budgetExitTriggered) {
                if ($wrapUpIterationsRemaining <= 0) {
                    $this->notify('agent.warning', 'Budget wrap-up window exhausted — forcing exit');

                    return new Output(
                        content: 'Context budget exhausted. Agent was given wrap-up iterations but did not complete.',
                        toolResults: $allToolResults,
                        usage: $totalUsage,
                        iterations: $i + 1,
                        conversation: $conversation,
                        finishReason: AgentFinishReason::BudgetExhausted,
                    );
                }
                $wrapUpIterationsRemaining--;
            }

            // Inject any pending external input into the conversation
            if ($this->pendingInputProvider !== null) {
                foreach ($this->pendingInputProvider->consumePendingInputs() as $pendingInput) {
                    $conversation->add($pendingInput);
                }
            }

            $this->notify('agent.iteration', $i + 1);

            try {
                $contentParts = [];
                $reasoningParts = [];
                $toolCalls = [];
                $streamUsage = null;
                $streamModel = '';

                foreach ($this->provider->stream($conversation->messages(), $advertisedTools) as $chunk) {
                    $this->tickCallback->tick();

                    // Check cancellation between stream chunks so ESC/Ctrl+C returns
                    // to the prompt immediately without waiting for the full response.
                    if ($this->cancellationToken?->isCancelled()) {
                        break;
                    }

                    if ($chunk->reasoning !== '') {
                        $reasoningParts[] = $chunk->reasoning;
                        $this->notify('agent.reasoning', $chunk->reasoning);
                    }

                    if ($chunk->content !== '') {
                        $contentParts[] = $chunk->content;
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

                $this->tickCallback->tick();

                $content = implode('', $contentParts);

                $response = new Response(
                    content: $content,
                    finishReason: !empty($toolCalls) ? ProviderFinishReason::ToolUse : ProviderFinishReason::Stop,
                    toolCalls: $toolCalls,
                    model: $streamModel,
                    usage: $streamUsage,
                    reasoning: implode('', $reasoningParts),
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
                    finishReason: AgentFinishReason::Error,
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

            // Budget threshold detection: fire once when usage crosses the
            // configured threshold. Emit an event so external observers (e.g.
            // Coqui's BudgetExitObserver) can inject wrap-up instructions via
            // PendingInputProvider for the NEXT iteration.
            if (
                !$budgetExitTriggered
                && $this->budgetExitThreshold > 0.0
                && $this->contextWindow !== null
                && $this->contextWindow->usagePercent() >= $this->budgetExitThreshold * 100
            ) {
                $budgetExitTriggered = true;
                $this->notify('agent.budget_warning', [
                    'usagePercent' => $this->contextWindow->usagePercent(),
                    'threshold' => $this->budgetExitThreshold,
                    'wrapUpIterations' => $this->budgetExitWrapUpIterations,
                ]);
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
                        finishReason: AgentFinishReason::Done,
                        reasoning: $response->reasoning,
                    );
                }
            }

            if (!empty($response->toolCalls)) {
                $consecutiveEmptyResponses = 0;
                $conversation->add(new AssistantMessage($response->content, $response->toolCalls));

                $executionResult = $this->executeToolCalls(
                    $response->toolCalls,
                    $executableToolIndex,
                    $conversation,
                    $allToolResults,
                );

                if ($executionResult !== null) {
                    return new Output(
                        content: $executionResult->getMessage(),
                        toolResults: $allToolResults,
                        usage: $totalUsage,
                        iterations: $i + 1,
                        conversation: $conversation,
                        finishReason: AgentFinishReason::Error,
                    );
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
                    finishReason: AgentFinishReason::Stop,
                    reasoning: $response->reasoning,
                );
            }

            // Empty response with no tool calls — apply the configured policy.
            // Some serving stacks (Ollama + qwen/gemma thinking models) route
            // the whole completion into reasoning and leave content empty.
            $consecutiveEmptyResponses++;
            $this->notify('agent.empty_response', [
                'attempt' => $consecutiveEmptyResponses,
                'maxRetries' => $this->maxEmptyResponseRetries,
                'hasReasoning' => $response->reasoning !== '',
            ]);

            if (
                $this->emptyResponseHandling === EmptyResponseHandling::Fallback
                && $response->reasoning !== ''
            ) {
                return $this->fallbackToReasoning($response, $conversation, $allToolResults, $totalUsage, $i + 1);
            }

            if ($this->emptyResponseHandling === EmptyResponseHandling::Ignore) {
                $conversation->add(new AssistantMessage($response->content));
                continue;
            }

            // Nudge / NudgeThenFallback: keep the empty assistant turn so role
            // alternation stays valid, then ask for a plain-text answer.
            if ($consecutiveEmptyResponses <= $this->maxEmptyResponseRetries) {
                $conversation->add(new AssistantMessage($response->content));
                $conversation->add(new UserMessage(
                    'Your previous reply contained no final answer text'
                    . ($response->reasoning !== '' ? ' (only internal reasoning)' : '')
                    . '. Reply again with your final answer as plain text.',
                ));
                continue;
            }

            if (
                $this->emptyResponseHandling === EmptyResponseHandling::NudgeThenFallback
                && $response->reasoning !== ''
            ) {
                return $this->fallbackToReasoning($response, $conversation, $allToolResults, $totalUsage, $i + 1);
            }

            $this->notify('agent.error', 'Model returned empty responses after ' . $consecutiveEmptyResponses . ' attempts');

            return new Output(
                content: 'Model returned empty responses after ' . $consecutiveEmptyResponses . ' attempts.',
                toolResults: $allToolResults,
                usage: $totalUsage,
                model: $response->model,
                iterations: $i + 1,
                conversation: $conversation,
                finishReason: AgentFinishReason::EmptyResponse,
                reasoning: $response->reasoning,
            );
        }

        $this->notify('agent.error', 'Max iterations reached');

        return new Output(
            content: 'Agent reached maximum iterations without completing.',
            toolResults: $allToolResults,
            usage: $totalUsage,
            iterations: $this->maxIterations(),
            conversation: $conversation,
            finishReason: AgentFinishReason::MaxIterations,
        );
    }

    /**
     * Return accumulated reasoning as the answer for an empty-content turn.
     *
     * Used when the provider routed the entire completion into the
     * reasoning channel (Ollama qwen/gemma thinking bug) and the policy
     * allows surfacing it instead of failing the turn.
     *
     * @param ToolResult[] $allToolResults
     */
    private function fallbackToReasoning(
        Response $response,
        Conversation $conversation,
        array $allToolResults,
        Usage $totalUsage,
        int $iterations,
    ): Output {
        $answer = trim($response->reasoning);

        $this->notify('agent.warning', 'Model returned reasoning only; using reasoning as the answer');

        $conversation->add(new AssistantMessage($answer));
        $this->notify('agent.done', ['response' => $answer]);

        return new Output(
            content: $answer,
            toolResults: $allToolResults,
            usage: $totalUsage,
            model: $response->model,
            iterations: $iterations,
            conversation: $conversation,
            finishReason: AgentFinishReason::Stop,
            reasoning: $response->reasoning,
        );
    }

    /**
     * Execute tool calls from a provider response in three phases:
     *
     * 1. Pre-flight (serial): tick, cancellation check, emit agent.tool_call,
     *    check execution policy, resolve tool. Collects approved tools into
     *    a batch and immediately handles denied/missing tools.
     * 2. Execution (batch or serial): if the executor supports batch execution
     *    and multiple approved tools exist, delegates to executeBatch().
     *    Otherwise executes each tool serially.
     * 3. Post-flight (serial): adds ToolResultMessages to conversation in call
     *    order, emits agent.tool_result events.
     *
     * Returns null on normal completion, or TerminationException if a tool
     * requested immediate loop termination (e.g. restart_coqui).
     *
     * @param ToolCall[] $toolCalls
     * @param array<string, ToolInterface> $executableToolIndex
     * @param ToolResult[] $allToolResults Accumulated results (modified by reference)
     */
    private function executeToolCalls(
        array $toolCalls,
        array $executableToolIndex,
        Conversation $conversation,
        array &$allToolResults,
    ): ?TerminationException {
        // === Phase 1: Pre-flight (serial) ===
        // Process each tool call: emit events, check policy, resolve tool.
        // Denied or unresolvable tools get immediate results.
        // Approved tools are collected for execution.

        /** @var array<int, array{toolCall: ToolCall, tool: ToolInterface}> */
        $approved = [];

        /** @var array<int, ToolResult> Pre-resolved results (denied, not found) keyed by position */
        $preResolved = [];

        foreach ($toolCalls as $position => $toolCall) {
            $this->tickCallback->tick();

            if ($this->cancellationToken?->isCancelled()) {
                break;
            }

            $this->notify('agent.tool_call', $toolCall);

            // Check execution policy
            if ($this->executionPolicy !== null) {
                $policyResult = $this->executionPolicy->shouldExecute(
                    $toolCall->name,
                    $toolCall->arguments,
                );

                if ($policyResult !== true) {
                    $preResolved[$position] = ToolResult::error(
                        "Denied by policy: {$policyResult}",
                    )->withCallId($toolCall->id);
                    continue;
                }
            }

            // Resolve tool
            try {
                $tool = $this->findTool($toolCall->name, $executableToolIndex);
            } catch (ToolNotFoundException $e) {
                $this->notify('agent.tool_error', $e->getMessage());
                $preResolved[$position] = ToolResult::error($e->getMessage())->withCallId($toolCall->id);
                continue;
            }

            $approved[$position] = ['toolCall' => $toolCall, 'tool' => $tool];
        }

        // === Phase 2: Execution ===
        $useBatch = $this->toolExecutor instanceof BatchToolExecutorInterface
            && count($approved) > 1;

        /** @var array<int, ToolResult> Execution results keyed by original position */
        $executedResults = [];
        $terminationException = null;

        if ($useBatch) {
            // Build ordered batch from approved tools
            $batchEntries = [];
            $positionMap = []; // batch index → original position
            foreach ($approved as $position => $entry) {
                $positionMap[] = $position;
                $batchEntries[] = [
                    'tool' => $entry['tool'],
                    'arguments' => $entry['toolCall']->arguments,
                ];
            }

            $this->notify('agent.batch_start', [
                'count' => count($batchEntries),
                'tools' => array_map(
                    fn(array $e) => $e['toolCall']->name,
                    $approved,
                ),
            ]);

            try {
                /** @var BatchToolExecutorInterface $batchExecutor */
                $batchExecutor = $this->toolExecutor;
                $batchResults = $batchExecutor->executeBatch($batchEntries);

                foreach ($batchResults as $batchIndex => $batchResult) {
                    $originalPosition = $positionMap[$batchIndex];
                    $callId = $approved[$originalPosition]['toolCall']->id;
                    $executedResults[$originalPosition] = $batchResult->withCallId($callId);
                }
            } catch (TerminationException $e) {
                $terminationException = $e;
                // parallel() rejects the entire batch when any task throws
                // TerminationException, so $batchResults is never assigned.
                // In practice only RestartTool throws this, which is never
                // called alongside other tools.
            }

            $this->notify('agent.batch_end', [
                'count' => count($batchEntries),
            ]);
        } else {
            // Serial execution (single tool or non-batch executor)
            foreach ($approved as $position => $entry) {
                if ($this->cancellationToken?->isCancelled()) {
                    break;
                }

                try {
                    $result = $this->toolExecutor->execute($entry['tool'], $entry['toolCall']->arguments);
                    $executedResults[$position] = $result->withCallId($entry['toolCall']->id);
                } catch (TerminationException $e) {
                    $executedResults[$position] = ToolResult::success($e->getMessage())
                        ->withCallId($entry['toolCall']->id);
                    $terminationException = $e;
                    break;
                } catch (\Throwable $e) {
                    $this->notify('agent.tool_error', $e->getMessage());
                    $executedResults[$position] = ToolResult::error($e->getMessage())
                        ->withCallId($entry['toolCall']->id);
                }
            }
        }

        // === Phase 3: Post-flight (serial, in call order) ===
        // Merge pre-resolved and executed results, emit events in original order.
        foreach ($toolCalls as $position => $toolCall) {
            $result = $preResolved[$position] ?? $executedResults[$position] ?? null;

            if ($result === null) {
                // Tool was skipped (cancellation during pre-flight)
                continue;
            }

            $allToolResults[] = $result;
            $conversation->add(new ToolResultMessage($result));
            $this->notify('agent.tool_result', $result);
        }

        return $terminationException;
    }

    /**
     * Collect all tools with name-based deduplication (last-registered wins),
     * then apply the maxTools cap for the provider's tool parameter.
     *
     * Order: standalone tools → toolkit tools → DoneTool.
     * If multiple tools share the same name, the later registration
     * silently overrides the earlier one. This allows workspace-installed
     * toolkit packages to replace core tools.
     *
     * When $maxTools > 0, the combined list is capped to that count.
     * DoneTool is always included and counts as one slot in the cap.
     * Standalone tools (from tools()) are prioritised over toolkit tools.
     *
     * @return ToolInterface[]
     */
    private function allTools(): array
    {
        $indexed = $this->collectAllToolsIndexed();

        // Apply tool cap if configured — preserves DoneTool and prioritises
        // standalone tools over toolkit tools by slicing last.
        if ($this->maxTools > 0 && count($indexed) > $this->maxTools) {
            $doneTool = $indexed[DoneTool::NAME];
            unset($indexed[DoneTool::NAME]);
            // Keep first (maxTools - 1) tools so DoneTool always fits in budget
            $capped = array_slice($indexed, 0, $this->maxTools - 1, true);
            $capped[DoneTool::NAME] = $doneTool;
            return array_values($capped);
        }

        return array_values($indexed);
    }

    /**
     * Collect all tools into a name-indexed map (no cap applied).
     *
     * @return array<string, ToolInterface>
     */
    private function collectAllToolsIndexed(): array
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

        return $indexed;
    }

    /**
     * @param ToolInterface[] $tools
     */
    private function buildSystemPrompt(array $tools): string
    {
        $prompt = SystemPrompt::withIdentity($this->instructions());
        $prompt = SystemPrompt::withIterationBudget($this->maxIter, $prompt);

        if (!empty($this->toolkits)) {
            $prompt = SystemPrompt::withToolkits($this->toolkits, $prompt);
        }

        return $this->finalizeSystemPrompt(SystemPrompt::render($prompt));
    }

    /**
     * Allow subclasses to append turn-scoped sections after the standard system prompt.
     */
    protected function finalizeSystemPrompt(string $prompt): string
    {
        return $prompt;
    }

    /**
     * @param array<string, ToolInterface> $toolIndex Name-indexed tool map for O(1) lookup.
     */
    private function findTool(string $name, array $toolIndex): ToolInterface
    {
        return $toolIndex[$name] ?? throw ToolNotFoundException::forName($name);
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
