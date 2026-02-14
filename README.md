# php-agents

PHP 8.4+ framework for building AI agents with tool-use loops, provider abstraction, and composable toolkits.

Build agents that can read files, browse the web, execute code, and use custom tools — powered by any OpenAI-compatible API, Anthropic, or local models via Ollama.

---

## Features

- **Agentic tool-use loop** — automatic iteration: the LLM calls tools, processes results, and decides when it's done
- **Multi-provider** — Ollama (local), OpenAI, Anthropic, OpenRouter, or any OpenAI-compatible endpoint
- **Bundled agents** — `FileAgent`, `WebAgent`, `CodeAgent` ready to use out of the box
- **Composable toolkits** — filesystem, web, shell, and memory toolkits that snap onto any agent
- **Observer pattern** — attach `SplObserver` to watch agent lifecycle events in real time
- **OpenClaw config** — centralized model routing with aliases, fallbacks, and per-provider settings
- **Zero framework coupling** — depends only on `symfony/http-client` and `psr/log`

---

## Requirements

- PHP 8.4 or later
- Extensions: `curl`, `json`, `mbstring`
- Composer 2.x
- [Ollama](https://ollama.ai) (recommended for local inference)

---

## Installation

```bash
composer require carmelosantana/php-agents
```

---

## Quick Start

Create an agent that can read files and answer questions about them:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use CarmeloSantana\PHPAgents\Agent\FileAgent;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Message\UserMessage;

$provider = new OllamaProvider(model: 'llama3.2');

$agent = new FileAgent(
    provider: $provider,
    rootPath: getcwd(),
    readOnly: true,
);

$output = $agent->run(new UserMessage('Summarize the README.md file.'));

echo $output->content . "\n";
```

> Make sure Ollama is running: `ollama serve` and a model is pulled: `ollama pull llama3.2`

---

## Bundled Agents

| Agent | Description | Toolkits |
|-------|-------------|----------|
| `FileAgent` | Read, write, search, and manage files within a sandboxed root path | `FilesystemToolkit` |
| `WebAgent` | Make HTTP requests and optionally search the web | `WebToolkit` |
| `CodeAgent` | Filesystem access + shell command execution with an allowlist | `FilesystemToolkit` + `ShellToolkit` |

---

## Providers

```php
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;

// Ollama (local — no API key needed)
$provider = new OllamaProvider(model: 'llama3.2');

// OpenAI
$provider = new OpenAICompatibleProvider(
    model: 'gpt-4o',
    baseUrl: 'https://api.openai.com/v1',
    apiKey: getenv('OPENAI_API_KEY'),
);

// Anthropic
$provider = new AnthropicProvider(
    model: 'claude-sonnet-4-20250514',
    apiKey: getenv('ANTHROPIC_API_KEY'),
);
```

---

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
        parent::__construct($provider, maxIter: 10);
    }

    public function instructions(): string
    {
        return 'You are a database agent. Query databases and return results.';
    }
}
```

Register toolkits in the constructor with `$this->addToolkit()` to give your agent capabilities.

---

## Creating Custom Tools

Define tools with typed parameters and a callback:

```php
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;

$tool = new Tool(
    name: 'word_count',
    description: 'Count words in the given text',
    parameters: [
        new StringParameter('text', 'The text to count words in', required: true),
    ],
    callback: function (array $args): ToolResult {
        $count = str_word_count($args['text']);
        return ToolResult::success("Word count: {$count}");
    },
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

---

## Examples

Working examples live in the [`examples/`](examples/) directory:

| Example | Description | Run |
|---------|-------------|-----|
| [CLI Chat](examples/cli-chat.php) | Interactive terminal conversation with an LLM | `php examples/cli-chat.php` |
| [README Summarizer](examples/web-summarizer/) | Web UI that auto-summarizes this README using a FileAgent | `php -S localhost:8080 -t examples/web-summarizer/` |

## License

MIT
