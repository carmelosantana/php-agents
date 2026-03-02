# Agents

Agents are the core abstraction — they combine a provider, tools, and policies into an iterative loop that can reason and act.

## AbstractAgent

All agents extend `AbstractAgent`, which provides the complete agent loop. Your subclass only needs to define:

1. `name()` — the agent's identity
2. `instructions()` — the system prompt
3. Tools (via toolkits or `addTool()`)

```php
<?php

declare(strict_types=1);

namespace Acme;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;

final class ResearchAgent extends AbstractAgent
{
    public function name(): string
    {
        return 'Research Assistant';
    }

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
        You are a research assistant. Your job is to:
        1. Search the web for information on the user's topic
        2. Read relevant pages
        3. Synthesize findings into a clear summary
        Always cite your sources.
        INSTRUCTIONS;
    }
}
```

## Constructor Parameters

```php
$agent = new ResearchAgent(
    provider: $provider,                    // Required: ProviderInterface
    maxIterations: 25,                      // Max tool-use loops (default: 50)
    executionPolicy: new MyPolicy(),        // Optional: tool gating
    cancellationToken: $token,              // Optional: cooperative cancellation
    pendingInputProvider: $inputProvider,    // Optional: external input injection
    contextWindow: new ContextWindow(...),  // Optional: token budget tracking
);
```

| Parameter | Type | Default | Purpose |
|-----------|------|---------|---------|
| `provider` | `ProviderInterface` | — | LLM to use for reasoning |
| `maxIterations` | `int` | `50` | Safety limit on tool-use loops |
| `executionPolicy` | `?ToolExecutionPolicyInterface` | `null` | Pre-execution tool gating |
| `cancellationToken` | `?CancellationTokenInterface` | `null` | Cooperative cancellation |
| `pendingInputProvider` | `?PendingInputProviderInterface` | `null` | Inject messages mid-loop |
| `contextWindow` | `?ContextWindowInterface` | `null` | Token budget tracking + auto-pruning |

## The Run Loop in Detail

```mermaid
flowchart TD
    START([agent.run input]) --> SYS[Build system prompt<br/>instructions + guidelines + tool schemas]
    SYS --> CONV[Create Conversation<br/>SystemMessage + history + UserMessage]
    CONV --> NOTIFY_START[notify: agent.start]
    NOTIFY_START --> LOOP

    subgraph LOOP [Agent Loop]
        CHECK_MAX{i < maxIterations?} -->|No| RETURN_MAX[Output: max iterations]
        CHECK_MAX -->|Yes| CTX{contextWindow?}
        CTX -->|Yes| PRUNE[fitWithinBudget]
        CTX -->|No| CHECK_CANCEL
        PRUNE --> CHECK_CANCEL

        CHECK_CANCEL{cancelled?} -->|Yes| RETURN_CANCEL[Output: cancelled]
        CHECK_CANCEL -->|No| INJECT[Inject pending inputs]
        INJECT --> NOTIFY_ITER[notify: agent.iteration]
        NOTIFY_ITER --> CHAT[provider.chat]

        CHAT -->|Error| RETURN_ERR[Output: error]
        CHAT -->|OK| TRACK[Track usage + report to contextWindow]
        TRACK --> ADD_ASST[Add AssistantMessage to conversation]

        ADD_ASST --> CHECK_DONE{DoneTool called?}
        CHECK_DONE -->|Yes| RETURN_DONE[Output: done response]

        CHECK_DONE -->|No| CHECK_TOOLS{Has tool calls?}
        CHECK_TOOLS -->|No, has content| RETURN_TEXT[Output: text response]
        CHECK_TOOLS -->|No, empty| RETRY[Add placeholder, continue]
        RETRY --> CHECK_MAX

        CHECK_TOOLS -->|Yes| EXEC_TOOLS[Execute each tool call]
        EXEC_TOOLS --> ADD_RESULTS[Add ToolResultMessages]
        ADD_RESULTS --> CHECK_MAX
    end

    LOOP --> NOTIFY_DONE[notify: agent.done]
```

## Built-in Agents

### FileAgent

Pre-loaded with `FilesystemToolkit`. Good for file manipulation tasks.

```php
use CarmeloSantana\PHPAgents\Agent\FileAgent;

$agent = new FileAgent(provider: $provider);
// Has: read_file, write_file, list_directory, search_files, file_info, create_directory, delete_file
```

### WebAgent

Pre-loaded with `WebToolkit`. Good for web research and API interaction.

```php
use CarmeloSantana\PHPAgents\Agent\WebAgent;

$agent = new WebAgent(provider: $provider);
// Has: http_request, web_search
```

### CodeAgent

Pre-loaded with `FilesystemToolkit` and `ShellToolkit`. Good for coding tasks.

```php
use CarmeloSantana\PHPAgents\Agent\CodeAgent;

$agent = new CodeAgent(provider: $provider);
// Has: file tools + execute_command
```

## Adding Tools and Toolkits

```php
// Add individual tools
$agent->addTool($myTool);

// Add a toolkit (registers all its tools + injects guidelines)
$agent->addToolkit(new WebToolkit());
$agent->addToolkit(new MemoryToolkit(memory: $memory));
```

Tools from toolkits are merged with directly-added tools. Guidelines from all toolkits are concatenated and appended to the system prompt.

## Observer Pattern

Agents implement `SplSubject`. Attach observers for logging, streaming UI, metrics, or any side-effect:

```php
use SplObserver;
use SplSubject;

final class MetricsObserver implements SplObserver
{
    private int $toolCalls = 0;
    private int $iterations = 0;

    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        match ($event) {
            'agent.iteration' => $this->iterations++,
            'agent.tool_call' => $this->toolCalls++,
            default => null,
        };
    }

    public function summary(): string
    {
        return sprintf('%d iterations, %d tool calls', $this->iterations, $this->toolCalls);
    }
}

$metrics = new MetricsObserver();
$agent->attach($metrics);
$output = $agent->run(new UserMessage('...'));
echo $metrics->summary();
```

### Events Reference

| Event | Data Type | Description |
|-------|-----------|-------------|
| `agent.start` | `MessageInterface` | The input message before first iteration |
| `agent.iteration` | `int` | Iteration number (1-based) |
| `agent.tool_call` | `ToolCall` | Before a tool is executed |
| `agent.tool_result` | `ToolResult` | After successful tool execution |
| `agent.tool_error` | `string` | When a tool throws an exception |
| `agent.done` | `array` | Agent completed (contains response data) |
| `agent.error` | `string` | Unrecoverable error in agent loop |

## Cooperative Cancellation

Long-running agents can be cancelled via `CancellationTokenInterface`:

```php
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;

final class TimeoutToken implements CancellationTokenInterface
{
    private readonly float $deadline;

    public function __construct(int $timeoutSeconds)
    {
        $this->deadline = microtime(true) + $timeoutSeconds;
    }

    public function isCancelled(): bool
    {
        return microtime(true) >= $this->deadline;
    }
}

$agent = new FileAgent(
    provider: $provider,
    cancellationToken: new TimeoutToken(30),
);

// Agent will stop after 30 seconds, even mid-loop
```

The cancellation check happens at the top of each iteration, so the current iteration always completes before stopping.

## Pending Input Injection

Inject messages into the agent loop from external sources (e.g., user typing while the agent is working):

```php
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;

final class QueuedInputProvider implements PendingInputProviderInterface
{
    private array $queue = [];

    public function addInput(MessageInterface $message): void
    {
        $this->queue[] = $message;
    }

    public function getPendingInputs(): array
    {
        $inputs = $this->queue;
        $this->queue = [];
        return $inputs;
    }
}

$inputProvider = new QueuedInputProvider();
$agent = new FileAgent(
    provider: $provider,
    pendingInputProvider: $inputProvider,
);

// From another thread/fiber:
$inputProvider->addInput(new UserMessage('Actually, also check the tests/ directory'));
```

## Context Window Integration

Prevent conversations from exceeding model token limits:

```php
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Context\TokenCounterFactory;

$contextWindow = new ContextWindow(
    tokenCounter: TokenCounterFactory::create(),
    maxTokens: 128_000, // Model's context limit
    reservedTokens: 4_000, // Space for the response
);

$agent = new FileAgent(
    provider: $provider,
    contextWindow: $contextWindow,
);
```

When the conversation approaches the token budget, the agent loop automatically:

1. Trims long tool results
2. Drops oldest conversation turns
3. Repairs orphaned tool-result/assistant-message pairs
4. Merges consecutive same-role messages

## The Output Value Object

`AbstractAgent::run()` returns an `Output` with the agent's response and metadata:

```php
$output = $agent->run(new UserMessage('...'));

$output->content;      // string — the final text response
$output->usage;        // Usage — total token usage across all iterations
$output->finishReason; // FinishReason — why the agent stopped
$output->toolCalls;    // ToolCall[] — tool calls from the final response (usually empty)
$output->history;      // MessageInterface[] — full conversation history
```

### Finish Reasons

| Reason | Meaning |
|--------|---------|
| `FinishReason::Stop` | LLM finished naturally or via DoneTool |
| `FinishReason::ToolCalls` | LLM wants to call tools (internal, shouldn't appear in final output) |
| `FinishReason::MaxTokens` | Response was truncated due to token limit |
| `FinishReason::Error` | An error occurred |
| `FinishReason::Cancelled` | Cancellation token triggered |
