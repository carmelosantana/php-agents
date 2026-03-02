# Getting Started

Install php-agents, configure a provider, and run your first agent in under five minutes.

## Requirements

- PHP 8.4+
- Composer 2.x
- At least one LLM provider (Ollama for local, or an API key for OpenAI/Anthropic)

## Installation

```bash
composer require carmelosantana/php-agents
```

### Optional Dependencies

```bash
# Accurate token counting for OpenAI models
composer require yethee/tiktoken-php

# Vector similarity search for persistent agent memory
composer require hkulekci/qdrant
```

## Provider Setup

### Ollama (Local, Free)

1. [Install Ollama](https://ollama.ai/download)
2. Pull a model:
   ```bash
   ollama pull llama3.2
   ```
3. Create an agent:
   ```php
   <?php

   declare(strict_types=1);

   require __DIR__ . '/vendor/autoload.php';

   use CarmeloSantana\PHPAgents\Agent\FileAgent;
   use CarmeloSantana\PHPAgents\Message\UserMessage;
   use CarmeloSantana\PHPAgents\Provider\OllamaProvider;

   $agent = new FileAgent(
       provider: new OllamaProvider(model: 'llama3.2'),
   );

   $output = $agent->run(new UserMessage('List the files in the current directory'));
   echo $output->content;
   ```

### OpenAI

```php
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;

$provider = new OpenAICompatibleProvider(
    model: 'gpt-4o',
    apiKey: getenv('OPENAI_API_KEY'),
);
```

### Anthropic (Claude)

```php
use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;

$provider = new AnthropicProvider(
    model: 'claude-sonnet-4-20250514',
    apiKey: getenv('ANTHROPIC_API_KEY'),
);
```

### OpenAI-Compatible Providers

Any provider with an OpenAI-compatible API works (OpenRouter, Together, Groq, etc.):

```php
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;

$provider = new OpenAICompatibleProvider(
    model: 'meta-llama/llama-3.1-70b-instruct',
    apiKey: getenv('OPENROUTER_API_KEY'),
    baseUrl: 'https://openrouter.ai/api/v1',
);
```

## Your First Agent

Agents combine a provider with tools and run an iterative loop until the task is complete:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use CarmeloSantana\PHPAgents\Agent\FileAgent;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;

$agent = new FileAgent(
    provider: new OllamaProvider(model: 'llama3.2'),
    maxIterations: 15,
);

$output = $agent->run(
    new UserMessage('Create a file called hello.txt with the contents "Hello, World!"'),
);

echo $output->content;
// Agent creates the file using FilesystemToolkit tools, then responds
```

The `FileAgent` comes pre-loaded with `FilesystemToolkit` (file read/write/list/search/delete) tools. The agent loop works like this:

1. Your message is sent to the LLM with tool definitions
2. The LLM decides which tool to call (e.g., `write_file`)
3. php-agents executes the tool and feeds the result back
4. The LLM decides if it's done or needs more tool calls
5. When done, it returns a natural language response

## Your First Custom Tool

Create a tool with typed parameters and a callback:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;

$calculator = new Tool(
    name: 'add',
    description: 'Add two numbers together',
    parameters: [
        new NumberParameter('a', 'First number', required: true),
        new NumberParameter('b', 'Second number', required: true),
    ],
    callback: fn(array $args): ToolResult => ToolResult::success(
        (string) ($args['a'] + $args['b']),
    ),
);

// Use it in an anonymous agent (extend AbstractAgent for custom behavior)
$agent = new class(
    provider: new OllamaProvider(model: 'llama3.2'),
) extends AbstractAgent {
    public function instructions(): string
    {
        return 'You are a calculator. Use the add tool to answer math questions.';
    }

    public function name(): string
    {
        return 'Calculator';
    }
};

// Register the tool
$agent->addTool($calculator);

$output = $agent->run(new UserMessage('What is 42 + 58?'));
echo $output->content;
```

## Your First Toolkit

Group related tools into a toolkit with shared guidelines:

```php
<?php

declare(strict_types=1);

namespace Acme\Weather;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class WeatherToolkit implements ToolkitInterface
{
    public function tools(): array
    {
        return [
            new Tool(
                name: 'get_weather',
                description: 'Get the current weather for a city',
                parameters: [
                    new StringParameter('city', 'City name', required: true),
                ],
                callback: fn(array $args): ToolResult => ToolResult::success(
                    json_encode($this->fetchWeather($args['city'])),
                ),
            ),
        ];
    }

    public function guidelines(): string
    {
        return 'Use the get_weather tool when the user asks about weather conditions.';
    }

    private function fetchWeather(string $city): array
    {
        // Your API call here
        return ['city' => $city, 'temp' => 72, 'condition' => 'sunny'];
    }
}
```

Register in your agent:

```php
$agent->addToolkit(new WeatherToolkit());
```

## Auto-Discovery for Toolkit Packages

If you publish your toolkit as a Composer package, add auto-discovery metadata to your `composer.json`:

```json
{
    "extra": {
        "php-agents": {
            "toolkits": ["Acme\\Weather\\WeatherToolkit"]
        }
    }
}
```

Host applications (like Coqui) scan installed packages for this metadata and register toolkits automatically.

## Observing Agent Behavior

Attach an observer to see what the agent is doing:

```php
use SplObserver;
use SplSubject;

$logger = new class implements SplObserver {
    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        match ($event) {
            'agent.iteration' => printf("[Iteration %d]\n", $data),
            'agent.tool_call' => printf("  → Calling: %s\n", $data->name),
            'agent.tool_result' => printf("  ← Result: %s\n", substr($data->content, 0, 100)),
            'agent.done' => print("Done!\n"),
            default => null,
        };
    }
};

$agent->attach($logger);
$output = $agent->run(new UserMessage('...'));
```

## Streaming Responses

For real-time output, use `stream()` on the provider directly:

```php
foreach ($provider->stream($messages, $tools) as $response) {
    if ($response->content !== '') {
        echo $response->content;
    }

    foreach ($response->toolCalls as $toolCall) {
        printf("\n[Tool Call: %s(%s)]\n", $toolCall->name, json_encode($toolCall->arguments));
    }
}
```

## Configuration with OpenClaw

php-agents can load settings from an `openclaw.json` configuration file:

```json
{
    "agents": {
        "defaults": {
            "provider": "ollama",
            "model": "llama3.2",
            "maxTokens": 4096,
            "temperature": 0.7
        }
    },
    "providers": {
        "ollama": {
            "baseUrl": "http://localhost:11434"
        },
        "openai": {
            "apiKey": "${OPENAI_API_KEY}"
        },
        "anthropic": {
            "apiKey": "${ANTHROPIC_API_KEY}"
        }
    }
}
```

## Next Steps

- [Architecture](architecture.md) — how all the pieces fit together
- [Providers](providers.md) — provider feature matrix and configuration
- [Tools & Toolkits](tools-and-toolkits.md) — parameter types, execution policies, publishing packages
- [Agents](agents.md) — extending AbstractAgent, the run loop, observers, cancellation
- [Memory](memory.md) — persistent memory, vector stores, embedding providers
