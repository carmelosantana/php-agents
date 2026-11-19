# Architecture

php-agents is a layered, composable PHP 8.4 framework for building AI agents with tool-use loops. This document explains the architecture, data flow, and extension points.

## High-Level Overview

```mermaid
graph TB
    subgraph "Your Application"
        APP[Application Code]
    end

    subgraph "php-agents Framework"
        subgraph "Agent Layer"
            AA[AbstractAgent]
        end

        subgraph "Provider Layer"
            PI[ProviderInterface]
            OAI[OpenAICompatibleProvider]
            ANT[AnthropicProvider]
            OLL[OllamaProvider]
            OAI --> PI
            ANT --> PI
            OLL --> OAI
        end

        subgraph "Tool Layer"
            TI[ToolInterface]
            TK[ToolkitInterface]
            T[Tool]
            DT[DoneTool]
            T --> TI
            DT --> TI
        end

        subgraph "Message Layer"
            MI[MessageInterface]
            SM[SystemMessage]
            UM[UserMessage]
            AM[AssistantMessage]
            TRM[ToolResultMessage]
            CONV[Conversation]
        end

        subgraph "Support Layer"
            CW[ContextWindow]
            TC[TokenCounter]
            OBS[SplObserver]
            CFG[OpenClawConfig]
        end

        AA --> PI
        AA --> TI
        AA --> TK
        AA --> MI
        AA --> CW
    end

    APP --> AA
    APP --> PI
    APP --> TK
```

## The Agent Loop

The core of php-agents is the iterative agent loop in `AbstractAgent::run()`. This is where the "agentic" behavior happens — the LLM decides what tools to call, processes results, and continues until it decides it's done. Every return path fires `agent.finish` once, just before `run()` returns.

```mermaid
flowchart TD
    START([run called]) --> BUILD[Build system prompt]
    BUILD --> CONV[Create Conversation<br/>system + history + input]
    CONV --> LOOP{i < maxIterations?}

    LOOP -->|No| MAX[Return: max iterations reached]

    LOOP -->|Yes| PRUNE{ContextWindow<br/>configured?}
    PRUNE -->|Yes| FIT[fitWithinBudget]
    PRUNE -->|No| CANCEL
    FIT --> CANCEL

    CANCEL{Cancelled?}
    CANCEL -->|Yes| CANCELLED[Return: cancelled]
    CANCEL -->|No| PENDING[Inject pending inputs]

    PENDING --> NOTIFY[Notify: agent.iteration]
    NOTIFY --> CHAT[provider.streamEvents messages, tools<br/>cancel and run limits checked per event]

    CHAT -->|Error| ERR[Return: provider error]
    CHAT -->|Cancelled| CANCELLED

    CHAT -->|Finish| USAGE[notify: agent.step_finish<br/>Accumulate token usage]
    USAGE --> BUDGET{Budget threshold<br/>crossed?}
    BUDGET -->|Yes| WARN[notify: agent.budget_warning]
    BUDGET -->|No| DONE{DoneTool in<br/>tool_calls?}
    WARN --> DONE

    DONE -->|Yes| RETURN_DONE[Return: done response]

    DONE -->|No| TOOLS{Has tool_calls?}

    TOOLS -->|Yes| EXEC_LOOP[For each tool call]
    EXEC_LOOP --> POLICY{Execution<br/>policy allows?}
    POLICY -->|No| DENIED[Add denied result]
    POLICY -->|Yes| EXECUTE[Execute tool]
    EXECUTE -->|TerminationException| TERM[Return immediately]
    EXECUTE -->|Success/Error| RESULT[Add ToolResultMessage]
    DENIED --> NEXT_TOOL{More tools?}
    RESULT --> NEXT_TOOL
    NEXT_TOOL -->|Yes| EXEC_LOOP
    NEXT_TOOL -->|No| LOOP

    TOOLS -->|No, has content| TEXT[Return: text response]
    TOOLS -->|No, empty| RETRY[Add empty assistant msg]
    RETRY --> LOOP
```

## Key Design Principles

### Composition Over Inheritance

Agents are composed from providers, toolkits, and policies rather than inheriting complex behavior:

```php
$agent = new class(
    provider: new OllamaProvider(model: 'llama3.2'),
    maxIter: 10,
    executionPolicy: new MyPolicy(),
) extends AbstractAgent {
    public function name(): string { return 'My Agent'; }
    public function instructions(): string { return 'You help users.'; }
};
```

### Interface-Driven Extensibility

Every major component has an interface contract. You can replace any layer:

| Interface | Purpose | Default Implementations |
|-----------|---------|------------------------|
| `ProviderInterface` | LLM communication | OpenAI, Anthropic, Ollama |
| `ToolInterface` | Tool definitions | `Tool` (closure-based) |
| `ToolkitInterface` | Tool groups + guidelines | `McpToolkit` |
| `ToolExecutionPolicyInterface` | Pre-execution gating | (none — implement your own) |
| `McpClientInterface` | Talking to an MCP server | `McpClient` (Streamable HTTP) |
| `McpSessionStore` | MCP session persistence | (none — implement your own) |
| `CancellationTokenInterface` | Cooperative cancellation | `NullCancellationToken` |
| `PendingInputProviderInterface` | External input injection | `NullPendingInputProvider` |
| `ContextWindowInterface` | Token budget tracking | `ContextWindow` |
| `VectorStoreInterface` | Similarity search | `InMemoryVectorStore` |
| `EmbeddingProviderInterface` | Text → vector | Ollama, OpenAI |
| `TokenCounterInterface` | Token counting | `HeuristicCounter`, `TiktokenCounter` |
| `ConfigInterface` | Configuration source | `OpenClawConfig` |

### The Observer Pattern

Agents implement `SplSubject` and emit events throughout the loop. Attach observers for logging, UI updates, streaming, or any side-effect:

```mermaid
sequenceDiagram
    participant App
    participant Agent
    participant Observer
    participant Provider
    participant Tool

    App->>Agent: run(UserMessage)
    Agent->>Observer: notify(agent.start)

    loop Each Iteration
        Agent->>Observer: notify(agent.iteration, n)
        Agent->>Provider: streamEvents(messages, tools)
        loop Each stream event
            Provider-->>Agent: TextDelta / ToolInputStart / ToolInputDelta / ToolInputEnd / Tick
            Agent->>Observer: notify(agent.text_delta, agent.tool_input_*, ...)
        end
        Provider-->>Agent: Finish
        Agent->>Observer: notify(agent.step_finish, StepFinish)

        alt Tool Calls
            Agent->>Observer: notify(agent.tool_call, toolCall)
            Agent->>Tool: execute(args)
            Tool-->>Agent: ToolResult
            Agent->>Observer: notify(agent.tool_result, result)
        end
    end

    Agent->>Observer: notify(agent.done, response)
    Agent->>Observer: notify(agent.finish, RunFinish)
    Agent-->>App: Output
```

**Events emitted:**

| Event | Data | When |
|-------|------|------|
| `agent.start` | `MessageInterface` | Before first iteration |
| `agent.iteration` | `int` (iteration number) | Top of each loop |
| `agent.reasoning` | `string` (fragment) | Each reasoning fragment as it streams |
| `agent.text_delta` | `string` (fragment) | Each text fragment as it streams |
| `agent.tool_input_start` | `ToolInputStart` | The model opens a tool call |
| `agent.tool_input_delta` | `ToolInputDelta` | Each fragment of a call's argument JSON |
| `agent.tool_input_end` | `ToolInputEnd` | A call's arguments are complete |
| `agent.done_delta` | `DonePreview` | Provisional text of the `done` call's answer |
| `agent.tool_input_discarded` | `ToolInputDiscarded` | A started call will not run |
| `agent.step_finish` | `StepFinish` | After each provider response: finish reason and usage |
| `agent.empty_response` | `array` | A response with no text and no tool calls |
| `agent.tool_call` | `ToolCall` | Before executing a tool |
| `agent.batch_start` | `array` | Before a batch executor runs the approved calls |
| `agent.batch_end` | `array` | After the batch returns |
| `agent.tool_result` | `ToolResult` | After tool execution |
| `agent.tool_error` | `string` (error message) | When a tool throws |
| `agent.budget_warning` | `array` | The context budget threshold was crossed |
| `agent.warning` | `string` | Non-fatal notice |
| `agent.error` | `string` (error message) | Unrecoverable error |
| `agent.done` | `array` (response) | Agent finished with an answer |
| `agent.finish` | `RunFinish` | Exactly once, last, on every exit path |

Payloads, the discard reasons and the event order on each exit path are in
[Agents](AGENTS.md#events-reference).

## Message Flow

Messages flow through the system in a structured conversation:

```mermaid
graph LR
    subgraph "Conversation"
        SYS[SystemMessage<br/>Agent instructions + tool schemas]
        U1[UserMessage<br/>User query]
        A1[AssistantMessage<br/>Tool calls]
        T1[ToolResultMessage<br/>Tool output]
        A2[AssistantMessage<br/>Final answer]

        SYS --> U1 --> A1 --> T1 --> A2
    end
```

Each message type maps to a specific role:

| Class | Role | Content | Special Fields |
|-------|------|---------|----------------|
| `SystemMessage` | `system` | `string` | — |
| `UserMessage` | `user` | `string\|array` (multimodal) | — |
| `AssistantMessage` | `assistant` | `string` | `toolCalls: ToolCall[]` |
| `ToolResultMessage` | `tool` | `string` | `callId: string` |

## Provider Architecture

Providers abstract LLM API differences behind a unified interface:

```mermaid
classDiagram
    class ProviderInterface {
        <<interface>>
        +chat(messages, tools, options) Response
        +stream(messages, tools, options) iterable~Response~
        +streamEvents(messages, tools, options) iterable~StreamEvent~
        +structured(messages, schema, options) mixed
        +models() ModelDefinition[]
        +isAvailable() bool
        +getModel() string
        +withModel(model) static
    }

    class AbstractProvider {
        <<abstract>>
        #model: string
        #baseUrl: string
        #apiKey: string
        #httpClient: HttpClientInterface
        +stream() final
        +streamEvents()*
        #headers() array
        #formatTools(tools) array
        #formatMessages(messages) array
        #parseResponse(data) Response
    }

    class OpenAICompatibleProvider {
        +chat()
        +streamEvents()
        +structured()
    }

    class OllamaProvider {
        +models()
        +isAvailable()
    }

    class AnthropicProvider {
        +chat()
        +streamEvents()
        +structured()
        -extractSystemAndMessages()
        -formatAnthropicMessage()
        -convertContentForAnthropic()
    }

    ProviderInterface <|.. AbstractProvider
    AbstractProvider <|-- OpenAICompatibleProvider
    OpenAICompatibleProvider <|-- OllamaProvider
    AbstractProvider <|-- AnthropicProvider
```

## Tool System

Tools are the actions an agent can take. They're defined with typed parameters and return structured results:

```mermaid
classDiagram
    class ToolInterface {
        <<interface>>
        +name() string
        +description() string
        +parameters() Parameter[]
        +execute(input) ToolResult
        +toFunctionSchema() array
    }

    class Tool {
        -name: string
        -description: string
        -parameters: Parameter[]
        -callback: Closure
    }

    class ToolResult {
        +status: ToolResultStatus
        +content: string
        +callId: string
        +success(content) ToolResult
        +error(message) ToolResult
    }

    class ToolCall {
        +id: string
        +name: string
        +arguments: array
    }

    class Parameter {
        <<abstract>>
        +name: string
        +description: string
        +required: bool
        +toSchema() array
    }

    class StringParameter
    class NumberParameter
    class BoolParameter
    class EnumParameter
    class ArrayParameter
    class ObjectParameter

    ToolInterface <|.. Tool
    Tool --> Parameter
    Tool --> ToolResult
    Parameter <|-- StringParameter
    Parameter <|-- NumberParameter
    Parameter <|-- BoolParameter
    Parameter <|-- EnumParameter
    Parameter <|-- ArrayParameter
    Parameter <|-- ObjectParameter
```

## MCP (`Mcp/`) and raw schemas (`Schema/`)

`CarmeloSantana\PHPAgents\Mcp` is a hand-rolled client for remote Model Context Protocol
servers over Streamable HTTP, speaking the 2026-07-28 and 2025-11-25 revisions and detecting
which one a server answers to. `McpServer` holds the endpoint, the static headers and the
limits; `McpClient` implements `McpClientInterface`'s `listTools()` and `callTool()` over an
injected `Symfony\Contracts\HttpClient\HttpClientInterface`, so a host can supply its own
egress; `McpToolkit` turns the tools a person approved — pinned to
`McpToolDefinition::fingerprint()` — into `SchemaTool`s and withholds any whose definition
moved. `Mcp\Internal` is where the wire lives and is not public API: the POST and its limits
(`HttpExchange`), the reply and its JSON-RPC envelope (`HttpReply`, `SseReader`), the
2026-07-28 header encodings (`HeaderValue`, `ParamHeaders`), and the mapping from a
`tools/call` result to a `ToolResult` (`ResultMapper`). Errors are an `McpException` tree under
`\RuntimeException`. See [Tools & Toolkits](TOOLS-AND-TOOLKITS.md#mcp-servers) for the host's
view.

`CarmeloSantana\PHPAgents\Schema` holds `JsonSchemaRepair`, which is needed because
`json_decode($json, true)` cannot tell a JSON object from a JSON array: an empty `{}` comes
back as `[]` and re-encodes as a list, which providers reject where a schema requires an
object. `repair()` restores objects at the keywords it lists, whose value must be one, and
leaves value keywords alone. It does not enter draft-07 `dependencies` or a keyword it does not
list, and an empty root `{}` comes back as `[]`, so the caller sets the root `type`.

## Context Window Management

The context window system prevents conversations from exceeding model limits:

```mermaid
flowchart LR
    subgraph "Token Counting"
        HC[HeuristicCounter<br/>chars ÷ 4]
        TK[TiktokenCounter<br/>Accurate for OpenAI]
        TCF[TokenCounterFactory]
        TCF --> HC
        TCF --> TK
    end

    subgraph "Budget Tracking"
        CW[ContextWindow]
        CW -->|estimate| PRE[Pre-flight check]
        CW -->|report| POST[Server-reported usage]
    end

    subgraph "Conversation Pruning"
        FIT[fitWithinBudget]
        FIT --> TRIM[trimToolResults]
        TRIM --> DROP[dropOldestTurns]
        DROP --> RETRIM[trimToolResults<br/>aggressive pass]
        RETRIM --> REPAIR[repairToolPairing]
        REPAIR --> MERGE[mergeConsecutiveRoles]
    end

    CW --> FIT
```

When `budgetExitThreshold` is enabled, `AbstractAgent` treats it as a generic loop policy: once the latest provider-reported usage for an iteration crosses the configured threshold, it emits `agent.budget_warning` and allows a small wrap-up window before returning `AgentFinishReason::BudgetExhausted`. Product-specific reactions, such as Coqui's workflow-aware wrap-up prompt, remain outside php-agents and are implemented via observers plus `PendingInputProviderInterface`.

## Embedding & Vector Store Architecture

```mermaid
graph TB
    subgraph "Vector Search"
        VSI[VectorStoreInterface]
        IMVS[InMemoryVectorStore]
    end

    subgraph "Embeddings"
        EPI[EmbeddingProviderInterface]
        OEP[OllamaEmbeddingProvider]
        OAEP[OpenAIEmbeddingProvider]
    end

    EPI --> OEP
    EPI --> OAEP
    EPI --> VSI
    VSI --> IMVS
```

## How Coqui Extends php-agents

[Coqui](https://github.com/coquibot/coqui) is a full product built on php-agents. It demonstrates the framework's extensibility without duplicating any core logic:

```mermaid
graph TB
    subgraph "Coqui Product Layer"
        OA[OrchestratorAgent]
        CHA[ChildAgent]
        REPL[RunCommand / REPL]
        API[ReactPHP API Server]
        SS[SessionStorage / SQLite]
        TD[ToolkitDiscovery]
        CR[CredentialResolver]
        BT[BackgroundTaskManager]
        SP[SpawnAgentTool]
        PE[PhpExecuteTool]
        OBS[TerminalObserver / SseObserver]
    end

    subgraph "php-agents Framework"
        AA[AbstractAgent]
        PI[ProviderInterface]
        TI[ToolInterface]
        TK[ToolkitInterface]
        TEP[ToolExecutionPolicyInterface]
        CT[CancellationTokenInterface]
        PIP[PendingInputProviderInterface]
        CONV[Conversation]
    end

    OA -->|extends| AA
    CHA -->|extends| AA
    SP -->|implements| TI
    PE -->|implements| TI
    TD -->|discovers| TK
    CR -->|wraps| TK
    API -->|uses| AA
    REPL -->|uses| AA
    BT -->|spawns| AA
    OBS -->|observes| AA
    SS -->|persists| CONV
```

**Key difference:** php-agents is a **library** (agent loop, providers, tools, messages). Coqui is a **product** (REPL, API server, session persistence, multi-agent orchestration, security policies, credential management). Coqui provides its own filesystem, shell, memory, and other toolkits as product-layer code. php-agents supplies the framework primitives.
