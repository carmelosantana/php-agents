<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Agent\Event\DonePreview;
use CarmeloSantana\PHPAgents\Agent\Event\RunFinish;
use CarmeloSantana\PHPAgents\Agent\Event\StepFinish;
use CarmeloSantana\PHPAgents\Agent\Event\ToolInputDiscarded;
use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Contract\BatchToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\BudgetPruningStrategyInterface;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolGrantInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\EmptyResponseHandling;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Exception\TerminationException;
use CarmeloSantana\PHPAgents\Exception\ToolNotFoundException;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Prompt\SystemPrompt;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Security\UntrustedText;
use CarmeloSantana\PHPAgents\Tool\DoneTool;
use CarmeloSantana\PHPAgents\Tool\Parameter\Parameter;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\UnknownKeys;
use SplObserver;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

abstract class AbstractAgent implements AgentInterface
{
    /**
     * Default maximum iterations when no role-specific or global override is configured.
     * Use 0 to indicate unlimited iterations (maps to PHP_INT_MAX internally).
     */
    public const int DEFAULT_MAX_ITERATIONS = 25;

    /** Characters of a provider exception message, and of an API error message, that reach Output and agent.error. */
    private const int PROVIDER_ERROR_MAX_CHARS = 300;

    /** Bytes of a provider's HTTP error body read before decoding it. */
    private const int PROVIDER_ERROR_BODY_MAX_BYTES = 65536;

    /** Characters of error tool-result text that reach the conversation, Output and events. */
    private const int TOOL_ERROR_MAX_CHARS = 500;

    private const int ERROR_CODE_MAX_CHARS = 64;

    /** Characters of a provider's raw finish reason, or model id, that reach agent.step_finish and agent.finish. */
    private const int FINISH_TELEMETRY_MAX_CHARS = 128;

    private const string RUN_TIME_LIMIT_REACHED = 'Run time limit reached.';

    private const string RESPONSE_SIZE_LIMIT_REACHED = 'Response size limit reached.';

    private const string TOKEN_LIMIT_REACHED = 'Token limit reached.';

    private const string INPUT_TOO_LARGE = 'Input is too large.';

    /** @var SplObserver[] */
    private array $observers = [];

    /** @var ToolkitInterface[] */
    private array $toolkits = [];

    /** @var ToolInterface[] */
    private array $addedTools = [];

    /**
     * Maximum number of tools exposed to the provider per iteration.
     * 0 = unlimited (default). When set, DoneTool always counts toward the cap.
     * Use setMaxTools() to configure — e.g. from OrchestratorAgent based on config.
     */
    protected int $maxTools = 0;

    private readonly ToolExecutorInterface $toolExecutor;

    private readonly TickCallbackInterface $tickCallback;

    private readonly RunLimits $runLimits;

    public function setMaxTools(int $max): void
    {
        $this->maxTools = $max;
    }

    /**
     * @param array<string, mixed> $providerOptions Provider-specific request options (e.g. max_tokens,
     *                                              thinking) sent with every stream() call in the loop.
     * @param ?RunLimits $runLimits Tool-call caps and the execution boundary for each run();
     *                              null means new RunLimits() (no caps, advertised tools only).
     */
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
        private readonly array $providerOptions = [],
        ?RunLimits $runLimits = null,
    ) {
        $this->toolExecutor = $toolExecutor ?? new SynchronousToolExecutor();
        $this->tickCallback = $tickCallback ?? new NullTickCallback();
        $this->runLimits = $runLimits ?? new RunLimits();
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

    /**
     * Add a single tool without writing a toolkit. Offered alongside tools().
     */
    public function addTool(ToolInterface $tool): static
    {
        $this->addedTools[] = $tool;

        return $this;
    }

    /**
     * Runs the agent loop, then fires agent.finish with a RunFinish built from the Output
     * it returns, so every exit path ends with exactly one agent.finish. The model is scrubbed
     * like a provider error, since a provider controls it.
     */
    public function run(MessageInterface $input, ?Conversation $history = null): Output
    {
        $output = $this->runLoop($input, $history);

        $this->notify('agent.finish', new RunFinish(
            $output->finishReason,
            $output->usage ?? new Usage(),
            $output->iterations,
            UntrustedText::forDisplay($output->model, self::FINISH_TELEMETRY_MAX_CHARS),
        ));

        return $output;
    }

    private function runLoop(MessageInterface $input, ?Conversation $history): Output
    {
        $runStartedNs = (float) hrtime(true);
        $this->notify('agent.start', $input);

        // Advertised tools are capped by maxTools and sent to the provider.
        // Only they are executable, plus any tool a ToolGrantInterface tool
        // grants during this run (added to executableToolIndex from the full
        // index). RunLimits::executeUnadvertisedTools makes the full index
        // executable instead. Both indexes are name-keyed and local to this
        // run, so grants never outlive it.
        $advertisedTools = $this->allTools();
        $fullToolIndex = $this->collectAllToolsIndexed();
        $executableToolIndex = $this->runLimits->executeUnadvertisedTools
            ? $fullToolIndex
            : array_combine(
                array_map(fn(ToolInterface $tool): string => $tool->name(), $advertisedTools),
                $advertisedTools,
            );
        $runToolCalls = 0;
        $toolSchemaTokens = $this->toolSchemaTokens($advertisedTools);
        // Provider-independent RunLimits totals: bytes received and local token estimates.
        $runResponseBytes = 0;
        $spentTokens = 0;
        $systemPrompt = $this->buildSystemPrompt($advertisedTools);

        $conversation = new Conversation();
        $conversation->add(new SystemMessage($systemPrompt));

        // Inject prior conversation history (skip system messages — we use our own).
        // Repair tool pairing before injection so broken history from interrupted
        // sessions (orphaned assistant tool_calls with no matching tool results)
        // is cleaned up before reaching any provider. Provider-native content
        // blocks live only within one run: the system prompt or tools may have
        // changed since, and the provider would reject the replayed thinking.
        if ($history !== null) {
            $history = $history->repairToolPairing();
            foreach ($history->messages() as $msg) {
                if ($msg->role() === \CarmeloSantana\PHPAgents\Enum\Role::System) {
                    continue;
                }
                $conversation->add($msg instanceof AssistantMessage ? $msg->withoutContentBlocks() : $msg);
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

        if ($this->inputTooLarge($input)) {
            return $this->runLimitOutput(self::INPUT_TOO_LARGE, $conversation, $allToolResults, $totalUsage, 0);
        }

        for ($i = 0; $i < $effectiveMax; $i++) {
            // Apply context window pruning when a budget is configured.
            // Use the full input window (maxTokens - reservedTokens) as the
            // budget target. availableTokens() is unsuitable here because after
            // report(totalTokens), usedTokens reflects the last call's total
            // (prompt + completion), effectively double-counting the conversation.
            if ($this->contextWindow !== null) {
                $budget = $this->contextWindow->maxTokens() - $this->contextWindow->reservedTokens();
                if ($budget > 0) {
                    $beforePruning = $conversation->messages();
                    $conversation = $conversation->fitWithinBudget($budget, $this->safetyMarginPercent, $this->pruningStrategy);

                    // Edited history invalidates replayed thinking blocks, so
                    // every assistant turn falls back to plain formatting.
                    if ($conversation->messages() !== $beforePruning) {
                        $conversation = $this->withoutContentBlocks($conversation);
                    }
                }
            }

            // Check cooperative cancellation before each iteration
            if ($this->cancellationToken?->isCancelled()) {
                return $this->cancelledOutput('', $conversation, $allToolResults, $totalUsage, $i + 1);
            }

            if ($this->runTimeExceeded($runStartedNs)) {
                return $this->runLimitOutput(self::RUN_TIME_LIMIT_REACHED, $conversation, $allToolResults, $totalUsage, $i + 1);
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
            // Each pending input is new input too, measured against maxInputTokens.
            if ($this->pendingInputProvider !== null) {
                foreach ($this->pendingInputProvider->consumePendingInputs() as $pendingInput) {
                    if ($this->inputTooLarge($pendingInput)) {
                        return $this->runLimitOutput(self::INPUT_TOO_LARGE, $conversation, $allToolResults, $totalUsage, $i + 1);
                    }
                    $conversation->add($pendingInput);
                }
            }

            $this->notify('agent.iteration', $i + 1);

            // Local request estimate: the conversation plus the tool schemas sent with it.
            // It never depends on what the provider reports.
            $requestTokens = $conversation->estimateTokens() + $toolSchemaTokens;
            $maxTokens = $this->runLimits->maxEstimatedTokens;
            if ($maxTokens > 0 && $spentTokens + $requestTokens > $maxTokens) {
                return $this->runLimitOutput(self::TOKEN_LIMIT_REACHED, $conversation, $allToolResults, $totalUsage, $i + 1);
            }
            $spentTokens += $requestTokens;
            $this->contextWindow?->estimate($requestTokens);

            $callBytes = 0;
            $streamLimit = null;
            $cancelled = false;
            $finish = null;
            $contentParts = [];
            $reasoningParts = [];
            // Calls opened in this response, keyed by index, and the ones whose
            // arguments completed, in the order they completed.
            /** @var array<int, ToolInputStart> $startedCalls */
            $startedCalls = [];
            /** @var array<int, ToolInputEnd> $endedCalls */
            $endedCalls = [];
            // The first `done` call to open in this response streams its answer live. The call
            // that finishes the run is resolved later in toolCalls order, which is the order
            // arguments completed; the two differ only when `done` calls interleave.
            $donePreview = null;
            $donePreviewIndex = null;
            // RunLimits::$maxToolCallsPerTurn while streaming: the first `done` call is outside
            // it; a call opened past it is discarded at once and its later events are ignored,
            // so it is never retained. A call whose end arrives without a start is admitted then.
            $perTurn = $this->runLimits->maxToolCallsPerTurn;
            $turnCalls = 0;
            $firstDoneIndex = null;
            /** @var array<int, string> $limitedCalls ids of the calls discarded under the cap */
            $limitedCalls = [];
            $admitCall = function (ToolInputStart|ToolInputEnd $call) use ($perTurn, &$turnCalls, &$firstDoneIndex, &$limitedCalls): bool {
                if ($call->name === DoneTool::NAME && ($firstDoneIndex ?? $call->index) === $call->index) {
                    $firstDoneIndex = $call->index;

                    return true;
                }
                if ($perTurn > 0 && $turnCalls >= $perTurn) {
                    $limitedCalls[$call->index] = $call->id;
                    $this->discardCalls([$call], 'tool_call_limit');

                    return false;
                }
                $turnCalls++;

                return true;
            };
            // Argument bytes streamed per call index, for eventBytes().
            /** @var array<int, int> $argumentBytes */
            $argumentBytes = [];

            try {
                foreach ($this->provider->streamEvents($conversation->messages(), $advertisedTools, $this->providerOptions) as $event) {
                    $this->tickCallback->tick();

                    // Check cancellation on every event, ticks included, so ESC/Ctrl+C
                    // returns to the prompt without waiting for the full response.
                    if ($this->cancellationToken?->isCancelled()) {
                        $cancelled = true;
                        break;
                    }

                    // Cumulative limits, checked before the event is used. Breaking
                    // abandons the stream.
                    $eventBytes = $this->eventBytes($event, $argumentBytes, $startedCalls, $limitedCalls);
                    $callBytes += $eventBytes;
                    $runResponseBytes += $eventBytes;
                    if ($this->runLimits->maxResponseBytes > 0 && $runResponseBytes > $this->runLimits->maxResponseBytes) {
                        $streamLimit = self::RESPONSE_SIZE_LIMIT_REACHED;
                        break;
                    }
                    if ($this->runTimeExceeded($runStartedNs)) {
                        $streamLimit = self::RUN_TIME_LIMIT_REACHED;
                        break;
                    }
                    // $spentTokens already includes this request's estimate.
                    if ($maxTokens > 0 && $spentTokens + (int) ceil($callBytes / 4) > $maxTokens) {
                        $streamLimit = self::TOKEN_LIMIT_REACHED;
                        break;
                    }

                    if ($event instanceof ReasoningDelta) {
                        $reasoningParts[] = $event->delta;
                        $this->notify('agent.reasoning', $event->delta);
                    } elseif ($event instanceof TextDelta) {
                        $contentParts[] = $event->delta;
                        $this->notify('agent.text_delta', $event->delta);
                    } elseif ($event instanceof ToolInputStart) {
                        $this->notify('agent.tool_input_start', $event);
                        if ($admitCall($event)) {
                            $startedCalls[$event->index] = $event;
                            if ($donePreview === null && $event->name === DoneTool::NAME) {
                                $donePreview = new DonePreviewReader();
                                $donePreviewIndex = $event->index;
                            }
                        }
                    } elseif (($event instanceof ToolInputDelta || $event instanceof ToolInputEnd) && isset($limitedCalls[$event->index])) {
                        continue;
                    } elseif ($event instanceof ToolInputDelta) {
                        $this->notify('agent.tool_input_delta', $event);
                        if ($event->index === $donePreviewIndex) {
                            $previewDelta = $donePreview?->push($event->delta) ?? '';
                            if ($previewDelta !== '') {
                                $this->notify('agent.done_delta', new DonePreview($event->id, $previewDelta));
                            }
                        }
                    } elseif ($event instanceof ToolInputEnd) {
                        $start = $startedCalls[$event->index] ?? null;
                        if ($start === null && !$admitCall($event)) {
                            continue;
                        }
                        // A call keeps the id and name it opened with, the ones the cap and the
                        // done preview went by; an end that names another is corrected.
                        if ($start !== null && ($start->id !== $event->id || $start->name !== $event->name)) {
                            $event = new ToolInputEnd($event->index, $start->id, $start->name, $event->arguments, $event->metadata);
                        }
                        $endedCalls[$event->index] = $event;
                        $this->notify('agent.tool_input_end', $event);
                    } elseif ($event instanceof Finish) {
                        $finish = $event;
                    }
                }

                $this->tickCallback->tick();
            } catch (\Throwable $e) {
                // A cancel that aborts the transport surfaces as a throw; it ends the run
                // the way a cancel seen between events does.
                if ($this->cancellationToken?->isCancelled()) {
                    $cancelled = true;
                } else {
                    $errorMessage = $this->providerErrorMessage($e);

                    $this->discardCalls($startedCalls, 'provider_error');
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
            }

            // A stream cut short by a cancel can end without a Finish; that is the cancel, not a
            // provider error.
            if ($finish === null && $streamLimit === null && $this->cancellationToken?->isCancelled()) {
                $cancelled = true;
            }

            // A cancel mid-stream abandons the response: no call of it runs, and the text
            // streamed so far and any `done` preview, separated by a blank line when both
            // are present, is the run's answer.
            if ($cancelled) {
                $this->discardCalls($startedCalls, 'cancelled');
                // A Finish can arrive before the cancel is seen (a transport abort throws on the
                // next read); its usage was spent.
                if ($finish?->usage !== null) {
                    $totalUsage = $totalUsage->plus($finish->usage);
                }

                return $this->cancelledOutput(self::cancelledAnswer($contentParts, $donePreview), $conversation, $allToolResults, $totalUsage, $i + 1);
            }

            if ($streamLimit !== null) {
                $this->discardCalls($startedCalls, 'run_limit');

                return $this->runLimitOutput($streamLimit, $conversation, $allToolResults, $totalUsage, $i + 1);
            }

            if ($finish !== null) {
                $this->discardUnrunCalls($startedCalls, $endedCalls, $finish->reason);
                $this->notify('agent.step_finish', new StepFinish(
                    $i + 1,
                    $finish->reason,
                    UntrustedText::forDisplay($finish->rawReason, self::FINISH_TELEMETRY_MAX_CHARS),
                    $finish->usage,
                    UntrustedText::forDisplay($finish->model, self::FINISH_TELEMETRY_MAX_CHARS),
                ));
            } else {
                $this->discardCalls($startedCalls, 'provider_error');
            }

            // A stream that ended without a Finish is a provider error.
            $finishReason = $finish->reason ?? ProviderFinishReason::Error;

            // Tool calls run only on a ToolUse stop. A MaxTokens stop keeps
            // its text and drops any (possibly truncated) tool calls. Calls keep
            // the order their arguments completed in, as stream() projects them.
            $completedCalls = array_values($endedCalls);
            $response = new Response(
                content: implode('', $contentParts),
                finishReason: $finishReason,
                toolCalls: $finishReason === ProviderFinishReason::ToolUse
                    ? array_map(static fn(ToolInputEnd $end): ToolCall => $end->toToolCall(), $completedCalls)
                    : [],
                model: $finish->model ?? '',
                usage: $finish?->usage,
                reasoning: implode('', $reasoningParts),
                contentBlocks: $finish->contentBlocks ?? [],
            );

            // The response counts as at least one token per 4 bytes received,
            // whatever usage the provider reports.
            $responseTokens = (int) ceil($callBytes / 4);
            $spentTokens += max($response->usage->completionTokens ?? 0, $responseTokens);
            $this->contextWindow?->estimate($requestTokens + $responseTokens);

            if ($response->usage !== null) {
                $totalUsage = $totalUsage->plus($response->usage);

                // Report actual usage to context window for accurate tracking
                $this->contextWindow?->report($response->usage);
            }

            // A cancel after the stream (on agent.step_finish, say) still stops a final answer,
            // text or `done`, from being accepted; the answer streamed so far is kept as on a
            // mid-stream cancel. A response of ordinary tool calls goes on to executeToolCalls,
            // which answers each call as cancelled; a refusal, filter, context-window or provider
            // error finish keeps its own reason; an empty response is checked before a fallback.
            if ($this->cancellationToken?->isCancelled() && $this->isFinalAnswer($response)) {
                $this->discardCalls($response->toolCalls !== [] ? $completedCalls : [], 'cancelled');

                return $this->cancelledOutput(self::cancelledAnswer($contentParts, $donePreview), $conversation, $allToolResults, $totalUsage, $i + 1, $response->model);
            }

            if ($maxTokens > 0 && $spentTokens > $maxTokens) {
                if ($response->toolCalls !== []) {
                    $this->discardCalls($completedCalls, 'run_limit');
                }

                return $this->runLimitOutput(self::TOKEN_LIMIT_REACHED, $conversation, $allToolResults, $totalUsage, $i + 1, $response->model);
            }

            // Budget threshold detection: fire once when usage crosses the
            // configured threshold. Emit an event so external observers (e.g.
            // Coqui's BudgetExitObserver) can inject wrap-up instructions via
            // PendingInputProvider for the NEXT iteration. The local estimate
            // counts too, so a context window that under-reports cannot hide usage.
            $usagePercent = $this->contextWindow !== null
                ? $this->windowUsagePercent($this->contextWindow, $requestTokens + $responseTokens)
                : 0.0;
            if (
                !$budgetExitTriggered
                && $this->budgetExitThreshold > 0.0
                && $this->contextWindow !== null
                && $usagePercent >= $this->budgetExitThreshold * 100
            ) {
                $budgetExitTriggered = true;
                $this->notify('agent.budget_warning', [
                    'usagePercent' => $usagePercent,
                    'threshold' => $this->budgetExitThreshold,
                    'wrapUpIterations' => $this->budgetExitWrapUpIterations,
                ]);
            }

            $failedStop = $this->failedStopOutput($response, $conversation, $allToolResults, $totalUsage, $i + 1);
            if ($failedStop !== null) {
                return $failedStop;
            }

            // The first `done` call is authorized by the policy and validated; when it passes
            // the run finishes. Otherwise it, and any later `done`, gets an error result that
            // goes through executeToolCalls with the rest of the response; each is discarded
            // first, so a UI retracts its preview before the run continues.
            $finishingCall = null;
            $doneDiscards = [];
            $doneResults = $this->resolveDoneCalls($response->toolCalls, $finishingCall, $doneDiscards);
            // The execution policy may cancel while it allows `done`.
            if ($finishingCall !== null && $this->cancellationToken?->isCancelled()) {
                $this->discardCalls($completedCalls, 'cancelled');

                return $this->cancelledOutput(self::cancelledAnswer($contentParts, $donePreview), $conversation, $allToolResults, $totalUsage, $i + 1, $response->model);
            }
            if ($finishingCall !== null) {
                foreach ($response->toolCalls as $position => $toolCall) {
                    if ($toolCall !== $finishingCall) {
                        $end = $completedCalls[$position];
                        $reason = $toolCall->name === DoneTool::NAME ? 'duplicate_done' : 'done_finished_run';
                        $this->notify('agent.tool_input_discarded', new ToolInputDiscarded($end->index, $end->id, $end->name, $reason));
                    }
                }

                $this->notify('agent.done', $finishingCall->arguments);

                return new Output(
                    content: (string) $finishingCall->arguments['response'],
                    toolResults: $allToolResults,
                    usage: $totalUsage,
                    model: $response->model,
                    iterations: $i + 1,
                    conversation: $conversation,
                    finishReason: AgentFinishReason::Done,
                    reasoning: $response->reasoning,
                    providerFinishReason: $response->finishReason,
                );
            }

            foreach ($doneDiscards as $position => $reason) {
                $end = $completedCalls[$position];
                $this->notify('agent.tool_input_discarded', new ToolInputDiscarded($end->index, $end->id, $end->name, $reason));
            }

            if (!empty($response->toolCalls)) {
                $consecutiveEmptyResponses = 0;
                // Only a tool round carries the provider's content blocks: the
                // thinking must go back with its tool_use. Text, empty and
                // failed turns are re-formatted, so an empty thinking-only
                // turn is never replayed.
                $conversation->add(new AssistantMessage($response->content, $response->toolCalls, $response->contentBlocks));

                $runLimitReached = false;
                $runTimeReached = false;
                $executionResult = $this->executeToolCalls(
                    $response->toolCalls,
                    $executableToolIndex,
                    $fullToolIndex,
                    $runToolCalls,
                    $runLimitReached,
                    $runStartedNs,
                    $runTimeReached,
                    $conversation,
                    $allToolResults,
                    $doneResults,
                    $limitedCalls !== [],
                );

                if ($executionResult !== null) {
                    return new Output(
                        content: $executionResult->getMessage(),
                        toolResults: $allToolResults,
                        usage: $totalUsage,
                        iterations: $i + 1,
                        conversation: $conversation,
                        finishReason: AgentFinishReason::Error,
                        providerFinishReason: $response->finishReason,
                    );
                }

                // A cancel during tools ends the run after every call has a result.
                if ($this->cancellationToken?->isCancelled()) {
                    return $this->cancelledOutput('', $conversation, $allToolResults, $totalUsage, $i + 1, $response->model);
                }

                if ($runTimeReached) {
                    return $this->runLimitOutput(self::RUN_TIME_LIMIT_REACHED, $conversation, $allToolResults, $totalUsage, $i + 1, $response->model);
                }

                if ($runLimitReached) {
                    $this->notify('agent.warning', sprintf('Tool call limit reached: at most %d calls per run.', $this->runLimits->maxToolCallsPerRun));

                    return new Output(
                        content: 'Tool call limit reached for this run.',
                        toolResults: $allToolResults,
                        usage: $totalUsage,
                        model: $response->model,
                        iterations: $i + 1,
                        conversation: $conversation,
                        finishReason: AgentFinishReason::BudgetExhausted,
                        providerFinishReason: $response->finishReason,
                    );
                }

                continue;
            }

            // Text-only response (no tool calls) — this IS the response.
            // The done tool is only needed after tool use to present results.
            if ($response->content !== '') {
                if ($this->cancellationToken?->isCancelled()) {
                    return $this->cancelledOutput($response->content, $conversation, $allToolResults, $totalUsage, $i + 1, $response->model);
                }

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
                    providerFinishReason: $response->finishReason,
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
                providerFinishReason: $response->finishReason,
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
     * End the run on a RunLimits cumulative limit: agent.error carries the fixed $content.
     *
     * @param ToolResult[] $allToolResults
     */
    private function runLimitOutput(
        string $content,
        Conversation $conversation,
        array $allToolResults,
        Usage $totalUsage,
        int $iterations,
        string $model = '',
    ): Output {
        $this->notify('agent.error', $content);

        return new Output(
            content: $content,
            toolResults: $allToolResults,
            usage: $totalUsage,
            model: $model,
            iterations: $iterations,
            conversation: $conversation,
            finishReason: AgentFinishReason::BudgetExhausted,
        );
    }

    /**
     * End the run on a cancel. $streamedText is the text the cancelled response streamed:
     * when non-empty it is the content and is appended to the conversation as an assistant
     * message with no tool calls; otherwise the content is 'Task was cancelled.'. agent.error
     * carries 'Task cancelled' for observers that predate AgentFinishReason::Cancelled.
     *
     * @param ToolResult[] $allToolResults
     */
    private function cancelledOutput(
        string $streamedText,
        Conversation $conversation,
        array $allToolResults,
        Usage $totalUsage,
        int $iterations,
        string $model = '',
    ): Output {
        if ($streamedText !== '') {
            $conversation->add(new AssistantMessage($streamedText));
        }

        $this->notify('agent.error', 'Task cancelled');

        return new Output(
            content: $streamedText !== '' ? $streamedText : 'Task was cancelled.',
            toolResults: $allToolResults,
            usage: $totalUsage,
            model: $model,
            iterations: $iterations,
            conversation: $conversation,
            finishReason: AgentFinishReason::Cancelled,
        );
    }

    /**
     * The answer a cancelled response leaves: the text it streamed, then the `done` answer
     * previewed so far, separated by a blank line when both are present.
     *
     * @param list<string> $contentParts
     */
    private static function cancelledAnswer(array $contentParts, ?DonePreviewReader $donePreview): string
    {
        return implode("\n\n", array_filter([implode('', $contentParts), $donePreview?->text() ?? ''], static fn(string $part): bool => $part !== ''));
    }

    /**
     * Whether the run would accept $response as its answer: a response with a `done` call, or
     * text with no tool calls under a finish that is not a failure (Stop, ToolUse, or MaxTokens).
     */
    private function isFinalAnswer(Response $response): bool
    {
        if ($response->toolCalls !== []) {
            foreach ($response->toolCalls as $toolCall) {
                if ($toolCall->name === DoneTool::NAME) {
                    return true;
                }
            }

            return false;
        }

        return $response->content !== '' && in_array(
            $response->finishReason,
            [ProviderFinishReason::Stop, ProviderFinishReason::ToolUse, ProviderFinishReason::MaxTokens],
            true,
        );
    }

    /**
     * The result of a call that a cancel stopped before it ran.
     */
    private function cancelledToolResult(ToolCall $toolCall): ToolResult
    {
        return (new ToolResult(ToolResultStatus::Error, 'Cancelled before it ran.', errorCode: 'cancelled'))
            ->withCallId($toolCall->id);
    }

    /**
     * Whether RunLimits::$maxRunSeconds has passed since $runStartedNs (hrtime(true)).
     */
    private function runTimeExceeded(float $runStartedNs): bool
    {
        $max = $this->runLimits->maxRunSeconds;

        return $max > 0.0 && ((float) hrtime(true) - $runStartedNs) / 1e9 > $max;
    }

    /**
     * Bytes one stream event carries, counting every field the agent keeps: the text of a text
     * or reasoning delta; a call's id and name on its ToolInputStart; each argument delta; on a
     * ToolInputEnd, the JSON of its arguments beyond the bytes already streamed as deltas for
     * that call, the JSON of its metadata, and its id and name when no start came first or
     * they differ from the start's; and a Finish's raw reason, model and the JSON of its content
     * blocks.
     *
     * @param array<int, int> $argumentBytes Argument bytes streamed per started call index so
     *                                       far; a start opens an entry and its deltas add to it
     * @param array<int, ToolInputStart> $startedCalls Calls kept so far, by index
     * @param array<int, string> $limitedCalls Ids of calls discarded under the per-turn cap, by index
     */
    private function eventBytes(StreamEvent $event, array &$argumentBytes, array $startedCalls, array $limitedCalls): int
    {
        if ($event instanceof TextDelta || $event instanceof ReasoningDelta) {
            return strlen($event->delta);
        }

        if ($event instanceof ToolInputStart) {
            $argumentBytes[$event->index] ??= 0;

            return strlen($event->id) + strlen($event->name);
        }

        if ($event instanceof ToolInputDelta) {
            $bytes = strlen($event->delta);
            // A delta with no start is counted here and not credited to the call's end.
            if (isset($argumentBytes[$event->index])) {
                $argumentBytes[$event->index] += $bytes;
            }

            return $bytes;
        }

        if ($event instanceof ToolInputEnd) {
            $bytes = max(0, self::jsonBytes($event->arguments) - ($argumentBytes[$event->index] ?? 0))
                + ($event->metadata === [] ? 0 : self::jsonBytes($event->metadata));
            $start = $startedCalls[$event->index] ?? null;
            $idAndName = strlen($event->id) + strlen($event->name);

            return $bytes + match (true) {
                $start !== null => $start->id === $event->id && $start->name === $event->name ? 0 : $idAndName,
                isset($limitedCalls[$event->index]) => 0,
                default => $idAndName,
            };
        }

        if ($event instanceof Finish) {
            return strlen($event->rawReason) + strlen($event->model)
                + ($event->contentBlocks === [] ? 0 : self::jsonBytes($event->contentBlocks));
        }

        return 0;
    }

    /**
     * Length of $value as JSON; bytes that are not UTF-8 count as substitutes.
     */
    private static function jsonBytes(mixed $value): int
    {
        return strlen(json_encode($value, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    /**
     * Fire agent.tool_input_discarded with $reason for each call, in order.
     *
     * @param array<int, ToolInputStart|ToolInputEnd> $calls
     */
    private function discardCalls(array $calls, string $reason): void
    {
        foreach ($calls as $call) {
            $this->notify('agent.tool_input_discarded', new ToolInputDiscarded($call->index, $call->id, $call->name, $reason));
        }
    }

    /**
     * Fire agent.tool_input_discarded for each call of a finished response that will not run:
     * a call whose arguments never completed is 'invalid_arguments' when the response finished
     * with Error, else 'not_tool_use'; a completed call is 'not_tool_use' when the response
     * finished with a reason other than ToolUse.
     *
     * @param array<int, ToolInputStart> $startedCalls
     * @param array<int, ToolInputEnd> $endedCalls
     */
    private function discardUnrunCalls(array $startedCalls, array $endedCalls, ProviderFinishReason $reason): void
    {
        foreach ($startedCalls as $index => $start) {
            $discardReason = match (true) {
                !isset($endedCalls[$index]) => $reason === ProviderFinishReason::Error ? 'invalid_arguments' : 'not_tool_use',
                $reason !== ProviderFinishReason::ToolUse => 'not_tool_use',
                default => null,
            };

            if ($discardReason !== null) {
                $this->notify('agent.tool_input_discarded', new ToolInputDiscarded($start->index, $start->id, $start->name, $discardReason));
            }
        }
    }

    /**
     * Whether $message is over RunLimits::$maxInputTokens (never, when that limit is 0).
     */
    private function inputTooLarge(MessageInterface $message): bool
    {
        return $this->runLimits->maxInputTokens > 0
            && $this->messageTokens($message) > $this->runLimits->maxInputTokens;
    }

    /**
     * Estimated tokens of one message, with the Conversation::estimateTokens() heuristic.
     */
    private function messageTokens(MessageInterface $message): int
    {
        $single = new Conversation();
        $single->add($message);

        return $single->estimateTokens();
    }

    /**
     * Estimated tokens of the tool schemas sent with each request (1 token per 4 bytes of JSON).
     *
     * @param ToolInterface[] $tools
     */
    private function toolSchemaTokens(array $tools): int
    {
        $schemas = json_encode(
            array_map(fn(ToolInterface $tool): array => $tool->toFunctionSchema(), $tools),
            \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return (int) ceil(strlen($schemas ?: '') / 4);
    }

    /**
     * The higher of the window's own usage percent and $localTokens over its input budget.
     */
    private function windowUsagePercent(ContextWindowInterface $window, int $localTokens): float
    {
        $effective = $window->maxTokens() - $window->reservedTokens();
        $local = $effective > 0 ? round(($localTokens / $effective) * 100, 1) : 100.0;

        return max($window->usagePercent(), $local);
    }

    /**
     * The text Output.content (after `Provider error: `) and agent.error carry for a
     * provider failure. The exception message is redacted and bounded. For a 4xx, at most
     * the first 64 KiB of the body are read and only a string `error.message` (Anthropic,
     * OpenAI) or top-level `message` is appended, redacted and bounded; the raw body never is.
     */
    private function providerErrorMessage(\Throwable $e): string
    {
        $message = UntrustedText::forDisplay($e->getMessage(), self::PROVIDER_ERROR_MAX_CHARS);
        if (!$e instanceof ClientExceptionInterface) {
            return $message;
        }

        try {
            $response = $e->getResponse();
            if ($response instanceof StreamableInterface) {
                $body = (string) stream_get_contents($response->toStream(false), self::PROVIDER_ERROR_BODY_MAX_BYTES);
            } else {
                $body = substr($response->getContent(false), 0, self::PROVIDER_ERROR_BODY_MAX_BYTES);
            }
            $decoded = json_decode($body, true);
        } catch (\Throwable) {
            return $message;
        }

        if (!is_array($decoded)) {
            return $message;
        }
        $apiMessage = is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)
            ? $decoded['error']['message']
            : ($decoded['message'] ?? null);

        return is_string($apiMessage)
            ? $message . "\n\nAPI response: " . UntrustedText::forDisplay($apiMessage, self::PROVIDER_ERROR_MAX_CHARS)
            : $message;
    }

    /**
     * A copy of the conversation with every AssistantMessage stripped of its
     * provider-native content blocks.
     */
    private function withoutContentBlocks(Conversation $conversation): Conversation
    {
        $stripped = new Conversation();
        foreach ($conversation->messages() as $message) {
            $stripped->add($message instanceof AssistantMessage ? $message->withoutContentBlocks() : $message);
        }

        return $stripped;
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
        // A cancel that landed after the response finished (on agent.empty_response, say).
        if ($this->cancellationToken?->isCancelled()) {
            return $this->cancelledOutput('', $conversation, $allToolResults, $totalUsage, $iterations, $response->model);
        }

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
            providerFinishReason: $response->finishReason,
        );
    }

    /**
     * End the run when the provider stopped for a reason other than Stop,
     * ToolUse or MaxTokens, or at MaxTokens with no text (its tool calls are
     * already dropped). Runs before done, tools and text, so no tool is
     * executed and no agent.done is emitted whatever arrived before the stop.
     *
     * Refusal and ContentFilter keep the text received as an assistant turn,
     * without tool calls. Returns null when the run should go on.
     *
     * @param ToolResult[] $allToolResults
     */
    private function failedStopOutput(
        Response $response,
        Conversation $conversation,
        array $allToolResults,
        Usage $totalUsage,
        int $iterations,
    ): ?Output {
        [$finishReason, $content] = match ($response->finishReason) {
            ProviderFinishReason::Refusal => [AgentFinishReason::Refusal, $response->content],
            ProviderFinishReason::ContentFilter => [AgentFinishReason::ContentFilter, $response->content],
            ProviderFinishReason::ContextWindowExceeded => [AgentFinishReason::ContextWindowExceeded, $response->content],
            ProviderFinishReason::Error => [AgentFinishReason::Error, 'Provider error: the response ended without a valid finish reason.'],
            ProviderFinishReason::PauseTurn => [AgentFinishReason::Error, 'Provider paused the turn (pause_turn); server-tool continuation is not supported.'],
            ProviderFinishReason::MaxTokens => $response->content === ''
                ? [AgentFinishReason::Error, 'Provider stopped at max_tokens before producing an answer; raise max_tokens in providerOptions.']
                : [null, ''],
            ProviderFinishReason::Stop, ProviderFinishReason::ToolUse => [null, ''],
        };

        if ($finishReason === null) {
            return null;
        }

        if ($finishReason === AgentFinishReason::Refusal || $finishReason === AgentFinishReason::ContentFilter) {
            $conversation->add(new AssistantMessage($content));
        }

        if ($finishReason === AgentFinishReason::Error) {
            $this->notify('agent.error', $content);
        }

        return new Output(
            content: $content,
            toolResults: $allToolResults,
            usage: $totalUsage,
            model: $response->model,
            iterations: $iterations,
            conversation: $conversation,
            finishReason: $finishReason,
            reasoning: $response->reasoning,
            providerFinishReason: $response->finishReason,
        );
    }

    /**
     * Execute tool calls from a provider response in three phases:
     *
     * 1. Pre-flight (serial): tick, cancellation check, emit agent.tool_call,
     *    apply the RunLimits per-turn and per-run caps, check execution policy,
     *    resolve tool. Collects approved tools into a batch and immediately
     *    handles capped/denied/missing tools. A capped call never reaches the
     *    policy or executor. A tool outside $executableToolIndex resolves as
     *    unknown, with the same text as a name that does not exist.
     * 2. Execution (batch or serial): if the executor supports batch execution
     *    and multiple approved tools exist, delegates to executeBatch().
     *    Otherwise executes each tool serially. A cancel seen before a call starts
     *    (in pre-flight, before the batch, or before a serial call) answers that call
     *    and every later one with 'Cancelled before it ran.' (error code 'cancelled');
     *    a running batch or tool is not interrupted.
     * 3. Post-flight (serial): adds ToolResultMessages to conversation in call
     *    order, emits agent.tool_result events, then adds the names granted by
     *    ToolGrantInterface tools that ran and succeeded to $executableToolIndex.
     *
     * Returns null on normal completion, or TerminationException if a tool
     * requested immediate loop termination (e.g. restart_coqui).
     *
     * @param ToolCall[] $toolCalls
     * @param array<string, ToolInterface> $executableToolIndex Tools the model may run (grants are added by reference)
     * @param array<string, ToolInterface> $fullToolIndex Every registered tool; a grant must name one
     * @param int $runToolCalls Calls sent to the policy/executor so far this run (modified by reference)
     * @param bool $runLimitReached Set when a call was refused by the per-run cap
     * @param float $runStartedNs hrtime(true) at the start of run(), for RunLimits::$maxRunSeconds
     * @param bool $runTimeReached Set when the run time limit stopped a tool or batch call; each
     *                             call it stopped gets an error result
     * @param ToolResult[] $allToolResults Accumulated results (modified by reference)
     * @param array<int, ToolResult> $resolvedCalls Results already decided for calls at these
     *                                              positions (refused `done` calls); they skip the
     *                                              cap, the policy and the executor
     * @param bool $turnLimitReached Calls of this response were discarded while streaming for
     *                               RunLimits::$maxToolCallsPerTurn; agent.warning fires after
     *                               the results
     */
    private function executeToolCalls(
        array $toolCalls,
        array &$executableToolIndex,
        array $fullToolIndex,
        int &$runToolCalls,
        bool &$runLimitReached,
        float $runStartedNs,
        bool &$runTimeReached,
        Conversation $conversation,
        array &$allToolResults,
        array $resolvedCalls = [],
        bool $turnLimitReached = false,
    ): ?TerminationException {
        // === Phase 1: Pre-flight (serial) ===
        // Process each tool call: emit events, check policy, resolve tool.
        // Denied or unresolvable tools get immediate results.
        // Approved tools are collected for execution.

        /** @var array<int, array{toolCall: ToolCall, tool: ToolInterface}> */
        $approved = [];

        /** @var array<int, ToolResult> Pre-resolved results (capped, denied, not found) keyed by position */
        $preResolved = [];

        $cancelled = false;
        $perRun = $this->runLimits->maxToolCallsPerRun;

        foreach ($toolCalls as $position => $toolCall) {
            $this->tickCallback->tick();

            // Once cancelled, every remaining call is answered without running.
            if ($cancelled || $this->cancellationToken?->isCancelled()) {
                $cancelled = true;
                $preResolved[$position] = $this->cancelledToolResult($toolCall);
                continue;
            }

            $this->notify('agent.tool_call', $toolCall);

            if (isset($resolvedCalls[$position])) {
                $preResolved[$position] = $resolvedCalls[$position];
                continue;
            }

            // The per-run cap: a refused call is answered here and never reaches the policy
            // or the executor. The per-turn cap was applied while the response streamed.
            if ($perRun > 0 && $runToolCalls >= $perRun) {
                $runLimitReached = true;
                $preResolved[$position] = ToolResult::error(
                    sprintf('Tool call limit reached: at most %d calls per run.', $perRun),
                )->withCallId($toolCall->id);
                continue;
            }

            $runToolCalls++;

            $denied = $this->policyDenial($toolCall);
            if ($denied !== null) {
                $preResolved[$position] = $denied;
                continue;
            }

            // Resolve tool
            try {
                $tool = $this->findTool($toolCall->name, $executableToolIndex);
            } catch (ToolNotFoundException $e) {
                $message = UntrustedText::forDisplay($e->getMessage(), self::TOOL_ERROR_MAX_CHARS);
                $this->notify('agent.tool_error', $message);
                $preResolved[$position] = ToolResult::error($message)->withCallId($toolCall->id);
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

        if ($useBatch && $this->cancellationToken?->isCancelled()) {
            foreach ($approved as $position => $entry) {
                $executedResults[$position] = $this->cancelledToolResult($entry['toolCall']);
            }
        } elseif ($useBatch && $this->runTimeExceeded($runStartedNs)) {
            $runTimeReached = true;
            foreach ($approved as $position => $entry) {
                $executedResults[$position] = ToolResult::error(self::RUN_TIME_LIMIT_REACHED)
                    ->withCallId($entry['toolCall']->id);
            }
        } elseif ($useBatch) {
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
                if ($cancelled || $this->cancellationToken?->isCancelled()) {
                    $cancelled = true;
                    $executedResults[$position] = $this->cancelledToolResult($entry['toolCall']);
                    continue;
                }

                if ($runTimeReached || $this->runTimeExceeded($runStartedNs)) {
                    $runTimeReached = true;
                    $executedResults[$position] = ToolResult::error(self::RUN_TIME_LIMIT_REACHED)
                        ->withCallId($entry['toolCall']->id);
                    continue;
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
                    $message = UntrustedText::forDisplay($e->getMessage(), self::TOOL_ERROR_MAX_CHARS);
                    $this->notify('agent.tool_error', $message);
                    $executedResults[$position] = ToolResult::error($message)
                        ->withCallId($entry['toolCall']->id);
                }
            }
        }

        // === Phase 3: Post-flight (serial, in call order) ===
        // Merge pre-resolved and executed results, emit events in original order.
        foreach ($toolCalls as $position => $toolCall) {
            $result = $preResolved[$position] ?? $executedResults[$position] ?? null;

            if ($result === null) {
                // A TerminationException stopped execution before this call ran
                continue;
            }

            // The single exposure boundary for error and timeout text: Output.toolResults,
            // the conversation and agent.tool_result all receive this result. Success
            // content and metadata are the tool author's data and are not rewritten.
            if ($result->status !== ToolResultStatus::Success) {
                $result = $this->redactedErrorResult($result);
            }

            $allToolResults[] = $result;
            $conversation->add(new ToolResultMessage($result));
            $this->notify('agent.tool_result', $result);
        }

        if ($turnLimitReached) {
            $this->notify('agent.warning', sprintf('Tool call limit reached: at most %d calls per turn.', $this->runLimits->maxToolCallsPerTurn));
        }

        $this->applyToolGrants($approved, $executedResults, $executableToolIndex, $fullToolIndex);

        return $terminationException;
    }

    /**
     * The execution policy's refusal of a call as an error result paired to it,
     * or null when there is no policy or it allows the call.
     */
    private function policyDenial(ToolCall $toolCall): ?ToolResult
    {
        if ($this->executionPolicy === null) {
            return null;
        }

        $decision = $this->executionPolicy->shouldExecute($toolCall->name, $toolCall->arguments);

        return $decision === true
            ? null
            : ToolResult::error("Denied by policy: {$decision}")->withCallId($toolCall->id);
    }

    /**
     * Decide the `done` calls in one response. Only the first is put to the policy and then
     * validated; when both pass it is returned in $finishingCall and no results are returned.
     * Otherwise every `done` call gets an error result keyed by its position: the refusal or
     * argument error for the first, a one-per-response error for the rest.
     *
     * @param ToolCall[] $toolCalls
     * @param ToolCall|null $finishingCall Set to the `done` call that finishes the run
     * @param array<string> $discardReasons Set, keyed by the position of each call given an error
     *                                      result, to its agent.tool_input_discarded reason:
     *                                      'denied', 'invalid_done' or 'duplicate_done'
     * @return array<int, ToolResult>
     */
    private function resolveDoneCalls(array $toolCalls, ?ToolCall &$finishingCall, array &$discardReasons): array
    {
        $finishingCall = null;
        $discardReasons = [];
        $results = [];
        $first = true;

        foreach ($toolCalls as $position => $toolCall) {
            if ($toolCall->name !== DoneTool::NAME) {
                continue;
            }

            if (!$first) {
                $results[$position] = ToolResult::error('Only one done call is allowed per response.')
                    ->withCallId($toolCall->id);
                $discardReasons[$position] = 'duplicate_done';
                continue;
            }

            $first = false;
            $error = $this->policyDenial($toolCall);
            $reason = 'denied';
            if ($error === null) {
                $error = $this->doneArgumentsError($toolCall);
                $reason = 'invalid_done';
            }
            if ($error === null) {
                $finishingCall = $toolCall;

                return [];
            }

            $results[$position] = $error;
            $discardReasons[$position] = $reason;
        }

        return $results;
    }

    /**
     * Validate `done` arguments against its schema: unknown keys are refused the way
     * Tool::execute refuses them, and `response` must be a string.
     */
    private function doneArgumentsError(ToolCall $toolCall): ?ToolResult
    {
        $unknownError = UnknownKeys::error(
            $toolCall->arguments,
            array_values(array_map(static fn(Parameter $param): string => $param->name, DoneTool::create()->parameters())),
        );

        if ($unknownError !== null) {
            return ToolResult::error($unknownError)->withCallId($toolCall->id);
        }

        if (!is_string($toolCall->arguments['response'] ?? null)) {
            return ToolResult::error('Parameter "response" must be a string.')->withCallId($toolCall->id);
        }

        return null;
    }

    /**
     * A failed (error or timeout) result with its content, every string key and value in its
     * metadata (recursively; an object is first turned into arrays through JSON, or null if that fails or throws) passed
     * through UntrustedText::forDisplay(…, 500), and its error code through forDisplay(…, 64).
     */
    private function redactedErrorResult(ToolResult $result): ToolResult
    {
        $redact = static function (mixed $value) use (&$redact): mixed {
            if (is_object($value)) {
                $flags = \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR;
                try {
                    $value = json_decode(json_encode($value, $flags) ?: 'null', true);
                } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (json_encode() runs a tool's jsonSerialize())
                    // A jsonSerialize() that throws is treated like a failed round trip.
                    $value = null;
                }
            }
            if (is_string($value)) {
                return UntrustedText::forDisplay($value, self::TOOL_ERROR_MAX_CHARS);
            }
            if (!is_array($value)) {
                return $value;
            }

            $redacted = [];
            foreach ($value as $key => $item) {
                $key = is_string($key) ? UntrustedText::forDisplay($key, self::TOOL_ERROR_MAX_CHARS) : $key;
                $redacted[$key] = $redact($item);
            }

            return $redacted;
        };

        /** @var array<string, mixed> $metadata */
        $metadata = $redact($result->metadata);

        return new ToolResult(
            $result->status,
            UntrustedText::forDisplay($result->content, self::TOOL_ERROR_MAX_CHARS),
            $result->callId,
            $metadata,
            $result->mimeType,
            $result->displayHint,
            $result->retryable,
            $result->errorCode === null ? null : UntrustedText::forDisplay($result->errorCode, self::ERROR_CODE_MAX_CHARS),
        );
    }

    /**
     * Add the names granted by ToolGrantInterface tools to the executable index.
     *
     * Only a tool whose call executed this round and returned a success result
     * is read, and only names present in the full index are added.
     *
     * @param array<int, array{toolCall: ToolCall, tool: ToolInterface}> $approved
     * @param array<int, ToolResult> $executedResults
     * @param array<string, ToolInterface> $executableToolIndex
     * @param array<string, ToolInterface> $fullToolIndex
     */
    private function applyToolGrants(
        array $approved,
        array $executedResults,
        array &$executableToolIndex,
        array $fullToolIndex,
    ): void {
        foreach ($approved as $position => $entry) {
            $tool = $entry['tool'];
            $result = $executedResults[$position] ?? null;

            if (!$tool instanceof ToolGrantInterface || $result?->status !== ToolResultStatus::Success) {
                continue;
            }

            foreach ($tool->grantedToolNames() as $name) {
                if (isset($fullToolIndex[$name])) {
                    $executableToolIndex[$name] = $fullToolIndex[$name];
                }
            }
        }
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

        foreach ([...$this->tools(), ...$this->addedTools] as $tool) {
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
