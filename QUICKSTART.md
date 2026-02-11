# QUICKSTART.md — php-agents

> `carmelosantana/php-agents` — PHP 8.4+ agent framework

---

## Requirements

- PHP 8.4 or later
- Extensions: `curl`, `json`, `mbstring`
- Composer 2.x

Optional:
- [Ollama](https://ollama.ai) — local LLM inference
- `yethee/tiktoken-php` — accurate token counting
- `hkulekci/qdrant` — vector similarity search

---

## Installation

```bash
composer require carmelosantana/php-agents
```

---

## Quick Start — FileAgent with Ollama

The simplest way to use php-agents: create a provider pointing at a local Ollama instance and run a `FileAgent`.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use CarmeloSantana\PHPAgents\Agent\FileAgent;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Message\UserMessage;

// 1. Create a provider (defaults to http://localhost:11434/v1)
$provider = new OllamaProvider(model: 'llama3.2');

// 2. Create a FileAgent rooted at the current directory
$agent = new FileAgent(
    provider: $provider,
    rootPath: getcwd(),
    readOnly: true,           // safe mode — no writes or deletes
);

// 3. Run the agent with a user message
$output = $agent->run(new UserMessage('List all PHP files in src/'));

// 4. Inspect the result
echo $output->content . "\n";
echo "Iterations: {$output->iterations}\n";
echo "Tokens used: {$output->usage->totalTokens}\n";

foreach ($output->toolResults as $result) {
    echo "  Tool: {$result->callId} → {$result->status->value}\n";
}
```

Make sure Ollama is running with a model pulled:

```bash
ollama pull llama3.2
```

---

## Bundled Agents

### FileAgent — Filesystem operations

```php
use CarmeloSantana\PHPAgents\Agent\FileAgent;

$agent = new FileAgent(
    provider: $provider,
    rootPath: '/path/to/project',  // all file ops are jailed here
    readOnly: false,               // allow writes and deletes
);
```

Tools: `read_file`, `write_file`, `list_dir`, `search_files`, `file_info`, `create_dir`, `delete_file`

### WebAgent — Web research and API calls

```php
use CarmeloSantana\PHPAgents\Agent\WebAgent;

$agent = new WebAgent(
    provider: $provider,
    searchEndpoint: 'https://api.search.example.com/v1/search',  // optional
    searchApiKey: 'your-key',                                      // optional
);
```

Tools: `http_request`, `web_search` (when endpoint configured)

### CodeAgent — Code generation with shell access

```php
use CarmeloSantana\PHPAgents\Agent\CodeAgent;

$agent = new CodeAgent(
    provider: $provider,
    rootPath: '/path/to/project',
    allowedCommands: ['php', 'composer', 'git', 'grep', 'find'],
);
```

Tools: All filesystem tools + `exec` (with command allowlist)

---

## Providers

### Ollama (local, default)

```php
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;

$provider = new OllamaProvider(
    model: 'llama3.2',
    baseUrl: 'http://localhost:11434/v1',
);
```

### OpenAI / OpenRouter / Any OpenAI-Compatible API

```php
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;

// OpenAI
$provider = new OpenAICompatibleProvider(
    model: 'gpt-4o',
    baseUrl: 'https://api.openai.com/v1',
    apiKey: getenv('OPENAI_API_KEY'),
);

// OpenRouter
$provider = new OpenAICompatibleProvider(
    model: 'anthropic/claude-sonnet-4-20250514',
    baseUrl: 'https://openrouter.ai/api/v1',
    apiKey: getenv('OPENROUTER_API_KEY'),
);
```

### Anthropic (native API)

```php
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;

$provider = new AnthropicProvider(
    model: 'claude-sonnet-4-20250514',
    apiKey: getenv('ANTHROPIC_API_KEY'),
);
```

### ProviderFactory — create from model string

```php
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Config\OpenClawConfig;

// Automatic provider routing based on prefix
$provider = ProviderFactory::fromModelString('ollama/llama3.2');
$provider = ProviderFactory::fromModelString('anthropic/claude-sonnet-4-20250514');
$provider = ProviderFactory::fromModelString('openai/gpt-4o');

// With OpenClaw config for API keys and base URLs
$config = OpenClawConfig::fromFile('openclaw.json');
$provider = ProviderFactory::fromModelString('ollama/llama3.2', $config);
```

---

## Configuration — openclaw.json

The `openclaw.json` file defines available models, providers, and routing:

```json
{
    "agents": {
        "defaults": {
            "models": {
                "ollama/llama3.2:latest": { "alias": "llama" },
                "ollama/qwen3:latest": { "alias": "qwen" }
            },
            "model": {
                "primary": "ollama/llama3.2:latest",
                "fallbacks": ["ollama/qwen3:latest"]
            }
        }
    },
    "models": {
        "providers": {
            "ollama": {
                "baseUrl": "http://localhost:11434/v1",
                "apiKey": "ollama-local",
                "models": [
                    {
                        "id": "llama3.2:latest",
                        "name": "Llama 3.2",
                        "input": ["text"],
                        "contextWindow": 128000,
                        "maxTokens": 4096
                    }
                ]
            }
        }
    }
}
```

---

## Observing Agent Events

Agents implement `SplSubject`. Attach `SplObserver` instances to receive lifecycle events:

```php
use SplObserver;
use SplSubject;

$observer = new class implements SplObserver {
    public function update(SplSubject $subject): void
    {
        $event = $subject->lastEvent;
        $data = $subject->lastEventData;

        match ($event) {
            'agent.start'       => printf("Agent started\n"),
            'agent.iteration'   => printf("Iteration %d\n", $data),
            'agent.tool_call'   => printf("Calling tool: %s\n", $data->name),
            'agent.tool_result' => printf("Tool result: %s\n", $data->status->value),
            'agent.done'        => printf("Agent finished\n"),
            'agent.error'       => printf("Error: %s\n", $data),
            default             => null,
        };
    }
};

$agent->attach($observer);
$output = $agent->run(new UserMessage('What files are in this directory?'));
```

---

## Custom Agents

Extend `AbstractAgent` to create your own agent:

```php
<?php

declare(strict_types=1);

namespace MyPackage;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;

final class DatabaseAgent extends AbstractAgent
{
    public function __construct(ProviderInterface $provider)
    {
        parent::__construct($provider, maxIter: 10);
        // Register your custom toolkit
        // $this->addToolkit(new DatabaseToolkit($dsn));
    }

    public function instructions(): string
    {
        return 'You are a database agent. You query databases and return results.';
    }

    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools];
    }
}
```

### Custom Tools

```php
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

$queryTool = new Tool(
    name: 'sql_query',
    description: 'Execute a read-only SQL query',
    parameters: [
        new StringParameter('query', 'The SQL SELECT query to execute', required: true),
    ],
    callback: function (array $args): ToolResult {
        // Your implementation here
        $result = 'Query results...';
        return ToolResult::success($result);
    },
);
```

---

## Publishing as a Composer Package

To make your agent discoverable by codito (the orchestration platform), add `extra.php-agents.agents` to your `composer.json`:

```json
{
    "name": "yourname/my-agents",
    "extra": {
        "php-agents": {
            "agents": [
                "MyPackage\\DatabaseAgent",
                "MyPackage\\AnotherAgent"
            ]
        }
    }
}
```

---

## Project Structure

```
php-agents/
├── src/
│   ├── Agent/          — AbstractAgent, FileAgent, WebAgent, CodeAgent, Output
│   ├── Config/         — OpenClawConfig, EnvConfig, ModelDefinition
│   ├── Context/        — ContextWindow, token counters
│   ├── Contract/       — All interfaces (11)
│   ├── Embedding/      — Ollama + OpenAI embedding providers
│   ├── Enum/           — Role, FinishReason, ModelCapability, ToolResultStatus
│   ├── Memory/         — FileMemory, Document, MemoryEntry, EmbeddingResult
│   ├── Message/        — System/User/Assistant/ToolResult messages, Conversation
│   ├── Prompt/         — SystemPrompt builder
│   ├── Provider/       — OpenAI-compatible, Anthropic, Ollama, ProviderFactory
│   ├── Tool/           — Tool, DoneTool, ToolCall, ToolResult, Parameter/
│   ├── Toolkit/        — Filesystem, Web, Shell, Memory toolkits
│   └── VectorStore/    — InMemoryVectorStore
└── tests/
    ├── Unit/
    └── Integration/
```
