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
            FA[FileAgent]
            WA[WebAgent]
            CA[CodeAgent]
            FA --> AA
            WA --> AA
            CA --> AA
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
            FST[FilesystemToolkit]
            WT[WebToolkit]
            ST[ShellToolkit]
            MT[MemoryToolkit]
            T --> TI
            FST --> TK
            WT --> TK
            ST --> TK
            MT --> TK
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

The core of php-agents is the iterative agent loop in `AbstractAgent::run()`. This is where the "agentic" behavior happens — the LLM decides what tools to call, processes results, and continues until it decides it's done.

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
    NOTIFY --> CHAT[provider.chat messages, tools]

    CHAT -->|Error| ERR[Return: provider error]

    CHAT -->|Success| USAGE[Accumulate token usage]
    USAGE --> DONE{DoneTool in<br/>tool_calls?}

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
$agent = new FileAgent(
    provider: new OllamaProvider(model: 'llama3.2'),
    maxIterations: 10,
    executionPolicy: new MyPolicy(),
);
```

### Interface-Driven Extensibility

Every major component has an interface contract. You can replace any layer:

| Interface | Purpose | Default Implementations |
|-----------|---------|------------------------|
| `ProviderInterface` | LLM communication | OpenAI, Anthropic, Ollama |
| `ToolInterface` | Tool definitions | `Tool` (closure-based) |
| `ToolkitInterface` | Tool groups + guidelines | Filesystem, Shell, Web, Memory |
| `ToolExecutionPolicyInterface` | Pre-execution gating | (none — implement your own) |
| `CancellationTokenInterface` | Cooperative cancellation | `NullCancellationToken` |
| `PendingInputProviderInterface` | External input injection | `NullPendingInputProvider` |
| `ContextWindowInterface` | Token budget tracking | `ContextWindow` |
| `MemoryInterface` | Persistent memory | `FileMemory` |
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
        Agent->>Provider: chat(messages, tools)
        Provider-->>Agent: Response

        alt Tool Calls
            Agent->>Observer: notify(agent.tool_call, toolCall)
            Agent->>Tool: execute(args)
            Tool-->>Agent: ToolResult
            Agent->>Observer: notify(agent.tool_result, result)
        end
    end

    Agent->>Observer: notify(agent.done, response)
    Agent-->>App: Output
```

**Events emitted:**

| Event | Data | When |
|-------|------|------|
| `agent.start` | `MessageInterface` | Before first iteration |
| `agent.iteration` | `int` (iteration number) | Top of each loop |
| `agent.tool_call` | `ToolCall` | Before executing a tool |
| `agent.tool_result` | `ToolResult` | After tool execution |
| `agent.tool_error` | `string` (error message) | When a tool throws |
| `agent.done` | `array` (response) | Agent finished |
| `agent.error` | `string` (error message) | Unrecoverable error |

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
        #headers() array
        #formatTools(tools) array
        #formatMessages(messages) array
        #parseResponse(data) Response
    }

    class OpenAICompatibleProvider {
        +chat()
        +stream()
        +structured()
    }

    class OllamaProvider {
        +models()
        +isAvailable()
    }

    class AnthropicProvider {
        +chat()
        +stream()
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
        DROP --> REPAIR[repairToolPairing]
        REPAIR --> MERGE[mergeConsecutiveRoles]
    end

    CW --> FIT
```

## Memory Architecture

```mermaid
graph TB
    subgraph "Agent-Facing"
        MTK[MemoryToolkit]
    end

    subgraph "Storage Layer"
        MI[MemoryInterface]
        FM[FileMemory<br/>Markdown file]
    end

    subgraph "Vector Search"
        VSI[VectorStoreInterface]
        IMVS[InMemoryVectorStore]
    end

    subgraph "Embeddings"
        EPI[EmbeddingProviderInterface]
        OEP[OllamaEmbeddingProvider]
        OAEP[OpenAIEmbeddingProvider]
    end

    MTK --> MI
    MI --> FM
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

**Key difference:** php-agents is a **library** (agent loop, providers, tools, messages). Coqui is a **product** (REPL, API server, session persistence, multi-agent orchestration, security policies, credential management). Coqui adds ~75 files of product logic on top of php-agents' ~74 files of framework code, with zero duplication.
