#!/usr/bin/env php
<?php

/**
 * CLI Chat — Simple conversational agent using php-agents.
 *
 * Usage:
 *   php examples/cli-chat.php
 *   php examples/cli-chat.php --model qwen3
 *
 * Requires Ollama running locally with a model pulled:
 *   ollama pull llama3.2
 *
 * To use a different provider, swap OllamaProvider for:
 *   - OpenAICompatibleProvider (OpenAI, OpenRouter, etc.)
 *   - AnthropicProvider (Claude)
 *
 * @see QUICKSTART.md for provider examples.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;

// --- Configuration -----------------------------------------------------------

$model = 'llama3.2';

// Parse --model flag from CLI arguments
foreach ($argv as $i => $arg) {
    if ($arg === '--model' && isset($argv[$i + 1])) {
        $model = $argv[$i + 1];
    }
}

$systemPrompt = <<<'PROMPT'
You are a helpful assistant. Answer questions clearly and concisely.
Keep responses focused and well-structured. Use markdown formatting when it improves readability.
PROMPT;

// --- Setup -------------------------------------------------------------------

$provider = new OllamaProvider(model: $model);

// Verify Ollama is reachable
if (!$provider->isAvailable()) {
    fwrite(STDERR, "Error: Cannot connect to Ollama at http://localhost:11434\n");
    fwrite(STDERR, "Make sure Ollama is running: ollama serve\n");
    exit(1);
}

$conversation = new Conversation();
$conversation->add(new SystemMessage($systemPrompt));

echo "php-agents CLI Chat (model: {$model})\n";
echo "Type your message and press Enter. Type 'exit' or 'quit' to stop.\n";
echo str_repeat('─', 60) . "\n\n";

// --- REPL Loop ---------------------------------------------------------------

while (true) {
    echo "\033[1;32mYou:\033[0m ";
    $input = fgets(STDIN);

    if ($input === false) {
        // EOF (e.g. piped input ended)
        break;
    }

    $input = trim($input);

    if ($input === '' ) {
        continue;
    }

    if (in_array(strtolower($input), ['exit', 'quit', 'q'], true)) {
        echo "\nGoodbye!\n";
        break;
    }

    $conversation->add(new UserMessage($input));

    try {
        $response = $provider->chat($conversation->messages(), []);
    } catch (\Throwable $e) {
        echo "\033[1;31mError:\033[0m {$e->getMessage()}\n\n";
        continue;
    }

    $conversation->add(new AssistantMessage($response->content));

    echo "\033[1;34mAssistant:\033[0m {$response->content}\n\n";

    // Show token usage when available
    if ($response->usage !== null && $response->usage->totalTokens > 0) {
        echo "\033[2m[tokens: {$response->usage->totalTokens}]\033[0m\n\n";
    }
}
