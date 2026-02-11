<?php

/**
 * Web Summarizer — Auto-summarizes the project README using a FileAgent.
 *
 * Usage:
 *   php -S localhost:8080 -t examples/web-summarizer/
 *   Then open http://localhost:8080 in your browser.
 *
 * Requires Ollama running locally:
 *   ollama pull llama3.2
 *
 * GET  /          → serves the HTML interface
 * POST /index.php → returns JSON with the README summary
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use CarmeloSantana\PHPAgents\Agent\FileAgent;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;

// --- API endpoint (POST) -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $model = $body['model'] ?? 'llama3.2';
    $projectRoot = dirname(__DIR__, 2);

    try {
        $provider = new OllamaProvider(model: $model);

        if (!$provider->isAvailable()) {
            http_response_code(503);
            echo json_encode(['error' => 'Cannot connect to Ollama. Make sure it is running: ollama serve']);
            exit;
        }

        $agent = new FileAgent(
            provider: $provider,
            rootPath: $projectRoot,
            readOnly: true,
        );

        $output = $agent->run(new UserMessage(
            'Read the README.md file and provide a clear, well-structured summary of the project. '
            . 'Include what the project does, its key features, and how to get started. '
            . 'Format the summary in markdown.',
        ));

        echo json_encode([
            'summary' => $output->content,
            'model' => $output->model ?: $model,
            'iterations' => $output->iterations,
            'tokens' => $output->usage?->totalTokens ?? 0,
            'tools_used' => array_map(
                fn($r) => ['id' => $r->callId, 'status' => $r->status->value],
                $output->toolResults,
            ),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}

// --- Serve HTML (GET) --------------------------------------------------------

$htmlPath = __DIR__ . '/index.html';

if (!file_exists($htmlPath)) {
    http_response_code(404);
    echo 'index.html not found';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile($htmlPath);
