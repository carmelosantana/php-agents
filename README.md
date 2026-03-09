# php-agents

<p align="center">
  <a href="https://github.com/carmelosantana/php-agents/actions/workflows/ci.yml?branch=main"><img src="https://img.shields.io/github/actions/workflow/status/carmelosantana/php-agents/ci.yml?branch=main&style=for-the-badge" alt="CI status"></a>
  <a href="https://github.com/carmelosantana/php-agents/releases"><img src="https://img.shields.io/github/v/release/carmelosantana/php-agents?include_prereleases&style=for-the-badge" alt="GitHub release"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.4+"></a>
  <a href="https://discord.gg/Vc29xdvGAH"><img src="https://img.shields.io/discord/1471632654624489668?label=Discord&logo=discord&logoColor=white&color=5865F2&style=for-the-badge" alt="Discord"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue?style=for-the-badge" alt="MIT License"></a>
</p>

PHP 8.4+ framework for building AI agents with tool-use loops, provider abstraction, and composable toolkits.

Build agents that can read files, browse the web, execute code, and use custom tools — powered by any OpenAI-compatible API, Anthropic, or local models via Ollama.

```mermaid
graph LR
    APP[Your App] --> AGENT[Agent]
    AGENT --> PROVIDER[Provider<br/>OpenAI / Anthropic / Ollama]
    AGENT --> TOOLS[Tools & Toolkits<br/>Files / Web / Shell / Memory]
    AGENT --> OBSERVER[Observers<br/>Logging / Streaming / Metrics]
    PROVIDER --> LLM[LLM]
    LLM -->|tool calls| AGENT
    TOOLS -->|results| AGENT
```

## Features

- **Agentic tool-use loop** — automatic iteration: the LLM calls tools, processes results, and decides when it's done
- **Multi-provider** — Ollama (local), OpenAI, Anthropic, Gemini, xAI, Mistral, OpenRouter, or any OpenAI-compatible endpoint
- **Streaming + tool calls** — all providers support streaming with assembled tool call deltas
- **Structured output** — extract typed data from LLMs via JSON mode (OpenAI) or tool-use trick (Anthropic)
- **Image input** — send images to vision models via base64, URL, or file path (auto-converts between provider formats; URLs pre-downloaded for providers that don't support them natively)
- **Bundled agents** — `FileAgent`, `WebAgent`, `CodeAgent` for quick prototyping *(deprecated — use `AbstractAgent` with toolkits for production)*
- **Composable toolkits** — filesystem, web, shell, and memory toolkits that snap onto any agent
- **Context window management** — automatic conversation pruning when approaching token limits
- **Observer pattern** — attach `SplObserver` to watch agent lifecycle events in real time
- **Security by default** — path traversal protection, SSRF blocking, shell injection detection
- **OpenClaw config** — centralized model routing with aliases, fallbacks, and per-provider settings
- **PSR-3 logging** — optional `LoggerInterface` on all providers for diagnostic visibility
- **Zero framework coupling** — depends only on `symfony/http-client` and `psr/log`

## Provider Feature Matrix

| Feature                | OpenAI Compatible | OpenAI Responses | Ollama | Anthropic | Gemini |  xAI  | Mistral |
| ---------------------- | :---------------: | :--------------: | :----: | :-------: | :----: | :---: | :-----: |
| `chat()`               |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |
| `stream()`             |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |
| `structured()`         |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |
| Tool calling           |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |
| Streaming + tool calls |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |
| Image input (base64)   |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |
| Image input (URL)      |         ✅         |        ✅         |   ✅    |     ✅     |   *    |   ✅   |    ✅    |
| `models()` list        |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |
| `isAvailable()`        |         ✅         |        ✅         |   ✅    |     ✅     |   ✅    |   ✅   |    ✅    |

*\* Gemini does not natively support URL image references. The provider auto-downloads URL images and converts them to base64 `inlineData`.*

## Requirements

- PHP 8.4 or later
- Extensions: `curl`, `json`, `mbstring`
- Composer 2.x
- [Ollama](https://ollama.ai) (recommended for local inference)

## Installation

```bash
composer require carmelosantana/php-agents
```

## Quick Start

Create an agent that can read files and answer questions about them:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Message\UserMessage;

// Create a simple file agent using AbstractAgent + FilesystemToolkit
final class MyFileAgent extends AbstractAgent
{
    public function __construct(ProviderInterface $provider, string $rootPath)
    {
        parent::__construct($provider);
        $this->addToolkit(new FilesystemToolkit($rootPath, readOnly: true));
    }

    public function instructions(): string
    {
        return 'You are a helpful file assistant. Read and summarize files when asked.';
    }

    public function name(): string
    {
        return 'FileAgent';
    }
}

$provider = new OllamaProvider(model: 'llama3.2');
$agent = new MyFileAgent($provider, getcwd());
$output = $agent->run(new UserMessage('Summarize the README.md file.'));

echo $output->content . "\n";
```

> Make sure Ollama is running: `ollama serve` and a model is pulled: `ollama pull llama3.2`

## Bundled Agents (Deprecated)

> **Note:** Bundled agents are deprecated and will be removed in 1.0. Use `AbstractAgent` with composable toolkits instead (see [Creating Custom Agents](#creating-custom-agents)).

| Agent       | Description                                                        | Toolkits                             |
| ----------- | ------------------------------------------------------------------ | ------------------------------------ |
| `FileAgent` | Read, write, search, and manage files within a sandboxed root path | `FilesystemToolkit`                  |
| `WebAgent`  | Make HTTP requests and optionally search the web                   | `WebToolkit`                         |
| `CodeAgent` | Filesystem access + shell command execution with an allowlist      | `FilesystemToolkit` + `ShellToolkit` |

## Providers

```php
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;

// Ollama (local — no API key needed)
$provider = new OllamaProvider(model: 'llama3.2');

// OpenAI
$provider = new OpenAICompatibleProvider(
    model: 'gpt-4o',
    apiKey: getenv('OPENAI_API_KEY'),
);

// Anthropic
$provider = new AnthropicProvider(
    model: 'claude-sonnet-4-20250514',
    apiKey: getenv('ANTHROPIC_API_KEY'),
);

// Google Gemini
$provider = new GeminiProvider(
    model: 'gemini-2.5-flash',
    apiKey: getenv('GEMINI_API_KEY'),
);

// xAI (Grok)
$provider = new XAIProvider(
    model: 'grok-3',
    apiKey: getenv('XAI_API_KEY'),
);

// Mistral
$provider = new MistralProvider(
    model: 'mistral-large-latest',
    apiKey: getenv('MISTRAL_API_KEY'),
);

// Any OpenAI-compatible endpoint (OpenRouter, Together, Groq, vLLM, etc.)
$provider = new OpenAICompatibleProvider(
    model: 'meta-llama/llama-3.1-70b-instruct',
    apiKey: getenv('OPENROUTER_API_KEY'),
    baseUrl: 'https://openrouter.ai/api/v1',
);
```

## Creating Custom Agents

Extend `AbstractAgent` and implement `instructions()`:

```php
<?php

declare(strict_types=1);

namespace MyPackage;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;

final class DatabaseAgent extends AbstractAgent
{
    public function __construct(ProviderInterface $provider)
    {
        parent::__construct($provider, maxIterations: 10);
    }

    public function instructions(): string
    {
        return 'You are a database agent. Query databases and return results.';
    }

    public function name(): string
    {
        return 'DatabaseAgent';
    }
}
```

Register toolkits in the constructor with `$this->addToolkit()` to give your agent capabilities.

## Creating Custom Tools

Define tools with typed parameters and a callback:

```php
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

$tool = new Tool(
    name: 'word_count',
    description: 'Count words in the given text',
    parameters: [
        new StringParameter('text', 'The text to count words in', required: true),
    ],
    callback: fn(array $args): ToolResult => ToolResult::success(
        'Word count: ' . str_word_count($args['text']),
    ),
);
```

Group related tools into a toolkit by implementing `ToolkitInterface`:

```php
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

final class MyToolkit implements ToolkitInterface
{
    public function tools(): array
    {
        return [$this->buildWordCountTool(), /* ... */];
    }

    public function guidelines(): string
    {
        return 'Use these tools to analyze text.';
    }
}
```

### Toolkit Auto-Discovery

Publish your toolkit as a Composer package with auto-discovery:

```json
{
    "extra": {
        "php-agents": {
            "toolkits": ["Acme\\MyToolkit\\MyToolkit"],
            "credentials": {
                "MY_API_KEY": "API key for MyService — get one at https://myservice.com/keys"
            }
        }
    }
}
```

## Documentation

| Guide                                          | Description                                              |
| ---------------------------------------------- | -------------------------------------------------------- |
| [Architecture](docs/architecture.md)           | System design, Mermaid diagrams, extension points        |
| [Getting Started](docs/getting-started.md)     | Installation, provider setup, first agent                |
| [Providers](docs/providers.md)                 | Feature matrix, streaming, structured output, images     |
| [Tools & Toolkits](docs/tools-and-toolkits.md) | Parameter types, execution policies, publishing packages |
| [Agents](docs/agents.md)                       | Agent loop, observers, cancellation, context window      |
| [Memory](docs/memory.md)                       | Persistent storage, vector search, embeddings            |

## Examples

Working examples live in the [`examples/`](examples/) directory:

| Example                                       | Description                                               | Run                                                 |
| --------------------------------------------- | --------------------------------------------------------- | --------------------------------------------------- |
| [CLI Chat](examples/cli-chat.php)             | Interactive terminal conversation with an LLM             | `php examples/cli-chat.php`                         |
| [README Summarizer](examples/web-summarizer/) | Web UI that auto-summarizes this README using a FileAgent | `php -S localhost:8080 -t examples/web-summarizer/` |

## `php-agents` In The Wild

[Coqui](https://github.com/AgentCoqui/coqui) is a full AI assistant product built on php-agents. It demonstrates the framework's extensibility:

- **php-agents** is the **library** — agent loop, providers, tools, messages, memory
- **Coqui** is the **product** — REPL, API server, session persistence, multi-agent orchestration, credential management, security policies, toolkit discovery

Coqui adds product logic on top of `php-agents` framework code, with zero code duplication. Each Coqui agent (`OrchestratorAgent`, `ChildAgent`) extends `AbstractAgent` — they are purely configuration layers.

## License

MIT
