<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

use function PHPAgentsExamples\finishMessage;
use function PHPAgentsExamples\providerNotice;
use function PHPAgentsExamples\readmeTool;
use function PHPAgentsExamples\safeModel;
use function PHPAgentsExamples\sanitizeTerminal;

require_once __DIR__ . '/../../../examples/helpers.php';

// The examples are local demos, but they still handle model output and request input.
// These pin the helpers that keep that handling safe.

test('the README tool takes no parameters and reads only README.md', function () {
    $readme = dirname(__DIR__, 3) . '/README.md';
    $tool = readmeTool($readme);

    expect($tool->parameters())->toBe([]);

    $result = $tool->execute([]);
    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toBe(file_get_contents($readme));

    // A path the model sends is an undeclared argument: refused before the tool runs.
    foreach ([['path' => '/etc/passwd'], ['path' => '../../.env', 'file' => 'composer.json']] as $input) {
        $result = $tool->execute($input);

        expect($result->status)->toBe(ToolResultStatus::Error)
            ->and($result->content)->toStartWith('Unknown parameters: ')
            ->and($result->content)->not->toContain('root:');
    }
});

test('the README tool reports a missing file without leaking its path', function () {
    $result = readmeTool('/nonexistent/secret-dir/README.md')->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->not->toContain('secret-dir');
});

test('safeModel keeps a plain model name and falls back on anything else', function (mixed $input, string $expected) {
    expect(safeModel($input, 'llama3.2'))->toBe($expected);
})->with([
    'ollama tag' => ['qwen3:8b', 'qwen3:8b'],
    'namespaced' => ['library/llama3.2:latest', 'library/llama3.2:latest'],
    'null' => [null, 'llama3.2'],
    'array' => [['x'], 'llama3.2'],
    'empty' => ['', 'llama3.2'],
    'space' => ['llama 3', 'llama3.2'],
    'query' => ['m?x=1', 'llama3.2'],
    'newline' => ["llama3.2\n", 'llama3.2'],
    'too long' => [str_repeat('a', 65), 'llama3.2'],
]);

test('finishMessage explains a refusal, a content filter and an error, and passes a clean finish', function () {
    expect(finishMessage(AgentFinishReason::Stop))->toBeNull()
        ->and(finishMessage(AgentFinishReason::Done))->toBeNull()
        ->and(finishMessage(AgentFinishReason::Refusal))->toContain('declined')
        ->and(finishMessage(AgentFinishReason::ContentFilter))->toContain('content filter')
        ->and(finishMessage(AgentFinishReason::Error))->toContain('error');

    foreach (AgentFinishReason::cases() as $reason) {
        if (!in_array($reason, [AgentFinishReason::Stop, AgentFinishReason::Done], true)) {
            expect(finishMessage($reason))->toBeString();
        }
    }
});

test('providerNotice is silent for stop and tool use and names any other reason', function () {
    expect(providerNotice(ProviderFinishReason::Stop))->toBeNull()
        ->and(providerNotice(ProviderFinishReason::ToolUse))->toBeNull()
        ->and(providerNotice(ProviderFinishReason::Refusal))->toBe('[stopped: refusal]')
        ->and(providerNotice(ProviderFinishReason::MaxTokens))->toBe('[stopped: max_tokens]');
});

test('sanitizeTerminal strips ANSI, OSC and C1 controls but keeps newlines, tabs and text', function () {
    $hostile = "ok\x1b[2J\x1b]0;pwned\x07 \xC2\x9B31m red\r\x7f\x00\n\tcafé ✓";

    $clean = sanitizeTerminal($hostile);

    expect($clean)->not->toMatch('/[\x00-\x08\x0B-\x1F\x7F]/')
        ->and($clean)->not->toContain("\xC2\x9B")
        ->and($clean)->toContain("\n\tcafé ✓")
        ->and($clean)->toStartWith('ok');
});

test('renderMarkdown escapes HTML and allows only safe link schemes', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }

    $script = dirname(__DIR__, 3) . '/examples/web-summarizer/markdown.js';
    $input = implode("\n", [
        '<img src=x onerror=alert(1)>',
        '[a](javascript:alert(1)) [b](https://example.com/?q="x") [c](mailto:me@example.com) [d](data:text/html,x)',
        '**bold** `<b>code</b>` \' "',
    ]);
    $js = 'const {renderMarkdown}=require(process.argv[1]);process.stdout.write(renderMarkdown(process.argv[2]));';
    $html = (string) shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($js) . ' '
        . escapeshellarg($script) . ' ' . escapeshellarg($input));

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->and($html)->not->toContain('href="javascript:')
        ->and($html)->not->toContain('href="data:')
        ->and($html)->toContain('<a href="https://example.com/?q=&quot;x&quot;" rel="noopener noreferrer">b</a>')
        ->and($html)->toContain('<a href="mailto:me@example.com" rel="noopener noreferrer">c</a>')
        ->and($html)->toContain('<strong>bold</strong>')
        ->and($html)->toContain('<code>&lt;b&gt;code&lt;/b&gt;</code>')
        ->and($html)->toContain('&#39; &quot;');
});
