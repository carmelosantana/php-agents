<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use Tests\Support\Docs\SnippetRunner;

// The streaming docs show observer code and a custom provider. These run the code blocks
// themselves, and check that the event lists name every event the agent fires.

$root = dirname(__DIR__, 3);

/**
 * The PHP code block that follows $heading in $path.
 */
function docCodeBlock(string $path, string $heading): string
{
    $doc = (string) file_get_contents($path);
    preg_match('/' . preg_quote($heading, '/') . '.*?```php\n(.*?)```/s', $doc, $m);

    return $m[1] ?? '';
}

test('the streaming example in AGENTS.md prints tool input and step usage', function () use ($root) {
    $code = docCodeBlock($root . '/docs/AGENTS.md', '### Streaming tool input and usage');

    expect($code)->toContain("'agent.tool_input_delta',")
        ->and($code)->toContain("'agent.step_finish',");

    $printed = SnippetRunner::run(<<<'PHP'
        <?php
        $agent = new \CarmeloSantana\PHPAgents\Agent\Agent(new \Tests\Support\Agent\ScriptedEventsProvider([
            [
                new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart(0, 'c1', 'lookup'),
                new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta(0, 'c1', '{"q":'),
                new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta(0, 'c1', '"php"}'),
                new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd(0, 'c1', 'lookup', ['q' => 'php']),
                new \CarmeloSantana\PHPAgents\Provider\Stream\Finish(\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::ToolUse, 'tool_use', new \CarmeloSantana\PHPAgents\Provider\Usage(10, 5, 15)),
            ],
            [
                new \CarmeloSantana\PHPAgents\Provider\Stream\TextDelta('Done.'),
                new \CarmeloSantana\PHPAgents\Provider\Stream\Finish(\CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop, 'end_turn', new \CarmeloSantana\PHPAgents\Provider\Usage(20, 2, 22)),
            ],
        ]));

        PHP . $code . <<<'PHP'

        $agent->run(new \CarmeloSantana\PHPAgents\Message\UserMessage('Look up php.'));
        PHP)->output();

    expect($printed)->toBe("{\"q\":\"php\"}\n[step 1: tool_use, 15 tokens]\n\n[step 2: stop, 22 tokens]\n");
});

test('the events reference lists every event the agent fires', function () use ($root) {
    $doc = (string) file_get_contents($root . '/docs/AGENTS.md');
    $architecture = (string) file_get_contents($root . '/docs/ARCHITECTURE.md');
    preg_match_all("/notify\\('(agent\\.[a-z_]+)'/", (string) file_get_contents($root . '/src/Agent/AbstractAgent.php'), $m);
    $events = array_unique($m[1]);

    expect($events)->toContain('agent.finish');
    foreach ($events as $event) {
        expect($doc)->toContain('| `' . $event . '` |')
            ->and($architecture)->toContain('| `' . $event . '` |');
    }
});

test('the discard reasons table lists every reason the agent uses', function () use ($root) {
    $doc = (string) file_get_contents($root . '/docs/AGENTS.md');
    $adr = (string) file_get_contents($root . '/docs/adr/0004-streaming-events.md');
    $source = (string) file_get_contents($root . '/src/Agent/AbstractAgent.php');

    $reasons = ['not_tool_use', 'invalid_arguments', 'run_limit', 'provider_error', 'cancelled', 'done_finished_run', 'denied', 'invalid_done', 'duplicate_done', 'tool_call_limit', 'tool_call_overflow'];
    foreach ($reasons as $reason) {
        expect($source)->toContain("'{$reason}'")
            ->and($doc)->toContain('| `' . $reason . '` |')
            ->and($adr)->toContain('`' . $reason . '`');
    }
});

test('the custom provider example streams typed events', function () use ($root) {
    $code = docCodeBlock($root . '/docs/PROVIDERS.md', '## Creating a Custom Provider');

    $output = SnippetRunner::run($code . <<<'PHP'

        $provider = new \Acme\MyProvider\MyProvider();
        echo serialize([
            'events' => iterator_to_array($provider->streamEvents([]), false),
            'chunks' => array_map(fn($c) => $c->content, iterator_to_array($provider->stream([]), false)),
            'chat' => $provider->chat([])->content,
        ]);
        PHP)->output();
    ['events' => $events, 'chunks' => $chunks, 'chat' => $chat] = unserialize($output);

    expect($code)->toContain('use StreamsEventsFromResponses;')
        ->and($events[0])->toEqual(new TextDelta('Hello'))
        ->and($events[1])->toEqual(new TextDelta(' world'))
        ->and(end($events))->toBeInstanceOf(Finish::class)
        ->and(end($events)->reason)->toBe(ProviderFinishReason::Stop)
        ->and($chat)->toBe('Hello from my provider')
        ->and($chunks)->toBe(['Hello', ' world']);
});

test('PROVIDERS.md documents streaming events per provider', function () use ($root) {
    $doc = (string) file_get_contents($root . '/docs/PROVIDERS.md');

    expect($doc)->toContain('## Streaming Events')
        ->and($doc)->toContain('### Cancellation and idle ticks')
        ->and($doc)->toContain('### Usage')
        ->and($doc)->toContain('### Raw finish reasons')
        ->and($doc)->toContain('`AbstractProvider::stream()` is `final`')
        ->and($doc)->toContain('never on an `AbstractProvider` subclass');
});

test('the upgrade notes cover each 0.18 breaking change', function () use ($root) {
    $doc = (string) file_get_contents($root . '/docs/UPGRADING.md');
    $readme = (string) file_get_contents($root . '/README.md');

    expect($readme)->toContain('(docs/UPGRADING.md)')
        ->and($doc)->toContain('## Upgrading to 0.18')
        ->and($doc)->toContain('use StreamsEventsFromResponses;')
        ->and($doc)->toContain('yield from $this->inner->streamEvents($messages, $tools, $options);')
        ->and($doc)->toContain('drop the call, on every provider, in `stream()` and `chat()`')
        ->and($doc)->toContain('AgentFinishReason::Cancelled')
        ->and($doc)->toContain('cacheReadTokens')
        ->and($doc)->toContain('| Every subclass of `AbstractProvider`: a 0.17 subclass defines `stream()`')
        ->and($doc)->toContain('never on an `AbstractProvider` subclass');
});

test('ADR 0004 is accepted', function () use ($root) {
    $adr = (string) file_get_contents($root . '/docs/adr/0004-streaming-events.md');

    expect($adr)->toContain('- Status: accepted (v0.18.0, 2026-11)');
});

test('ADR 0004 and AGENTS.md agree on the tool-call limit and mid-stream cancel sequences', function (string $path) use ($root) {
    $doc = (string) file_get_contents($root . '/' . $path);

    expect($doc)->toContain('| `step_finish` → … tools … → `warning` → `finish` | `BudgetExhausted` |')
        ->and($doc)->toContain('| `tool_input_discarded` (`cancelled`, each started or end-only call) → `error` `\'Task cancelled\'` → `finish` | `Cancelled` |');
})->with(['docs/AGENTS.md', 'docs/adr/0004-streaming-events.md']);
