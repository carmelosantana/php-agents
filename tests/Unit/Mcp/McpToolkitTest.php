<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Mcp\McpClient;
use CarmeloSantana\PHPAgents\Mcp\McpClientInterface;
use CarmeloSantana\PHPAgents\Mcp\McpException;
use CarmeloSantana\PHPAgents\Mcp\McpServer;
use CarmeloSantana\PHPAgents\Mcp\McpToolDefinition;
use CarmeloSantana\PHPAgents\Mcp\McpToolkit;
use CarmeloSantana\PHPAgents\Mcp\McpToolName;
use CarmeloSantana\PHPAgents\Mcp\McpTransportException;
use CarmeloSantana\PHPAgents\Tool\SchemaTool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Tests\Support\Mcp\FakeMcpServer;

final class StubMcpClient implements McpClientInterface
{
    public int $listed = 0;

    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];

    /** @param list<McpToolDefinition> $tools */
    public function __construct(public array $tools = [], public ?Throwable $listError = null, public ?Throwable $callError = null) {}

    public function listTools(): array
    {
        $this->listed++;
        if ($this->listError !== null) {
            throw $this->listError;
        }

        return $this->tools;
    }

    public function callTool(string $name, array $arguments): ToolResult
    {
        $this->calls[] = [$name, $arguments];
        if ($this->callError !== null) {
            throw $this->callError;
        }

        return ToolResult::success("called {$name}");
    }
}

function definitionNamed(string $name, string $description = 'D.', array $annotations = []): McpToolDefinition
{
    return new McpToolDefinition($name, $description, ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], $annotations);
}

test('only allowlisted tools whose fingerprint still matches are exposed, and drift is reported once', function () {
    $search = definitionNamed('search');
    $changed = definitionNamed('report', 'Now does something else.');
    $unapproved = definitionNamed('secret');
    $reported = [];
    $kit = new McpToolkit(
        new StubMcpClient([$search, $changed, $unapproved]),
        ['search' => $search->fingerprint(), 'report' => definitionNamed('report')->fingerprint(), 'gone' => str_repeat('0', 64)],
        'trk',
        function (array $drifted) use (&$reported): void { $reported[] = $drifted; },
    );
    $tools = $kit->tools();
    $kit->tools();

    expect(array_map(static fn($t) => $t->name(), $tools))->toBe(['trk__search'])
        ->and($tools[0])->toBeInstanceOf(SchemaTool::class)
        ->and($tools[0]->description())->toBe('D.')
        ->and($tools[0]->toFunctionSchema()['function']['parameters'])->toBe($search->inputSchema)
        ->and($reported)->toBe([[$changed]]);
});

test('the drift callback is left alone when every listed allowlisted tool still matches', function () {
    $search = definitionNamed('search');
    $called = 0;
    $kit = new McpToolkit(new StubMcpClient([$search]), ['search' => $search->fingerprint()], '', function () use (&$called): void { $called++; });
    $kit->tools();

    expect($called)->toBe(0);
});

test('drift with no callback withholds the tool and raises nothing', function () {
    $changed = definitionNamed('report', 'Now does something else.');

    expect((new McpToolkit(new StubMcpClient([$changed]), ['report' => definitionNamed('report')->fingerprint()]))->tools())->toBe([]);
});

test('the server is listed once per instance, whatever is asked', function () {
    $search = definitionNamed('search');
    $client = new StubMcpClient([$search]);
    $kit = new McpToolkit($client, ['search' => $search->fingerprint()]);
    $kit->tools();
    $kit->definition('search');
    $kit->tools();

    expect($client->listed)->toBe(1);
});

test('definition() alone lists, and a second instance lists again and sees the change', function () {
    $search = definitionNamed('search');
    $allow = ['search' => $search->fingerprint()];
    $client = new StubMcpClient([$search]);

    expect((new McpToolkit($client, $allow))->definition('search'))->toBe($search)
        ->and($client->listed)->toBe(1);

    $client->tools = [];

    expect((new McpToolkit($client, $allow))->tools())->toBe([])
        ->and($client->listed)->toBe(2);
});

test('an exposed tool calls the server by the name the server knows', function () {
    $search = definitionNamed('repo.search');
    $client = new StubMcpClient([$search]);
    $tool = (new McpToolkit($client, ['repo.search' => $search->fingerprint()], 'gh'))->tools()[0];

    expect($tool->name())->toBe(McpToolName::fit('gh__repo.search'))
        ->and($tool->execute(['q' => 'x'])->content)->toBe('called repo.search')
        ->and($client->calls)->toBe([['repo.search', ['q' => 'x']]]);
});

test('with no prefix the tool name is only fitted', function () {
    $search = definitionNamed('search');

    expect((new McpToolkit(new StubMcpClient([$search]), ['search' => $search->fingerprint()]))->tools()[0]->name())->toBe('search');
});

test('a namer replaces the naming rule, and a namer that answers nothing is an error', function () {
    $search = definitionNamed('search');
    $allow = ['search' => $search->fingerprint()];
    $named = new McpToolkit(new StubMcpClient([$search]), $allow, 'ignored', null, static fn(string $tool): string => "mcp_docs_{$tool}");
    $broken = new McpToolkit(new StubMcpClient([$search]), $allow, '', null, static fn(string $tool): string => '');
    $nonString = new McpToolkit(new StubMcpClient([$search]), $allow, '', null, static fn(string $tool) => 42);

    expect($named->tools()[0]->name())->toBe('mcp_docs_search')
        ->and(fn() => $broken->tools())->toThrow(UnexpectedValueException::class)
        ->and(fn() => $nonString->tools())->toThrow(UnexpectedValueException::class);
});

/**
 * The namer's failure is deliberately outside the McpException tree: it is the host's
 * closure misbehaving, not the server. Both assertions below name concrete classes.
 * `expect(...)->toThrow($name)` only type-checks when `class_exists($name)` is true
 * (vendor/pestphp/pest/src/Mixins/Expectation.php, the `! class_exists($exception)`
 * branch); handed an interface name it falls through to a substring match on the
 * exception message instead, so an interface must never be the argument to toThrow().
 * McpException is a class, so `not->toBeInstanceOf(McpException::class)` is a real
 * instanceof — and toBeInstanceOf does not share toThrow()'s class_exists() branch.
 */
test('the namer failure is not an McpException', function () {
    $search = definitionNamed('search');
    $broken = new McpToolkit(new StubMcpClient([$search]), ['search' => $search->fingerprint()], '', null, static fn(string $tool): string => '');
    $caught = null;
    try {
        $broken->tools();
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(UnexpectedValueException::class)
        ->and($caught)->not->toBeInstanceOf(McpException::class)
        ->and($caught)->not->toBeInstanceOf(McpTransportException::class);
});

test('definition() maps an exposed name back to the live definition and its hints', function () {
    $delete = definitionNamed('delete', 'Deletes.', ['destructiveHint' => true]);
    $kit = new McpToolkit(new StubMcpClient([$delete]), ['delete' => $delete->fingerprint()], 'trk');

    expect($kit->definition('trk__delete'))->toBe($delete)
        ->and($kit->definition('trk__delete')?->destructive())->toBeTrue()
        ->and($kit->definition('delete'))->toBeNull()
        ->and($kit->definition('trk__nope'))->toBeNull();
});

test('when two tools land on one exposed name the last listed wins', function () {
    $first = definitionNamed('first');
    $second = definitionNamed('second');
    $kit = new McpToolkit(
        new StubMcpClient([$first, $second]),
        ['first' => $first->fingerprint(), 'second' => $second->fingerprint()],
        '',
        null,
        static fn(string $tool): string => 'collides',
    );

    expect($kit->tools())->toHaveCount(1)
        ->and($kit->definition('collides'))->toBe($second)
        ->and($kit->tools()[0]->execute([])->content)->toBe('called second');
});

test('a listing failure propagates and is not remembered', function () {
    $client = new StubMcpClient([], new McpTransportException('MCP tools/list timed out.'));
    $kit = new McpToolkit($client, []);

    expect(fn() => $kit->tools())->toThrow(McpTransportException::class)
        ->and(fn() => $kit->tools())->toThrow(McpTransportException::class)
        ->and($client->listed)->toBe(2);
});

test('a failing call becomes a fixed error result', function () {
    $search = definitionNamed('search');
    $tool = (new McpToolkit(new StubMcpClient([$search], null, new McpTransportException('MCP tools/call timed out.')), ['search' => $search->fingerprint()]))->tools()[0];
    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toBe('The search tool failed before it could answer.')
        ->and($result->errorCode)->toBe('schema_tool_error')
        ->and($result->metadata['exception'] ?? null)->toBe(McpTransportException::class);
});

test('guidelines are empty, since server instructions are untrusted', function () {
    expect((new McpToolkit(new StubMcpClient(), []))->guidelines())->toBe('');
});

test('a non-string pin exposes nothing and is not reported as drift', function () {
    $search = definitionNamed('search');
    $reported = [];
    $kit = new McpToolkit(
        new StubMcpClient([$search]),
        ['search' => ['not', 'a', 'pin']],
        '',
        function (array $drifted) use (&$reported): void { $reported[] = $drifted; },
    );

    expect($kit->tools())->toBe([])
        ->and($reported)->toBe([]);
});

test('end to end: a pinned tool on a 2026-07-28 server runs through McpClient', function () {
    $fake = new FakeMcpServer();
    $fake->tools = [FakeMcpServer::tool('search')];
    $fake->results['search'] = static fn(array $a) => ['content' => [['type' => 'text', 'text' => 'hits: ' . ($a['q'] ?? '')]]];
    $client = new McpClient(new McpServer('https://mcp.example.test/mcp'), $fake->client());
    $pin = $client->listTools()[0]->fingerprint();
    $tool = (new McpToolkit($client, ['search' => $pin], 'ex'))->tools()[0];

    expect($tool->name())->toBe('ex__search')
        ->and($tool->execute(['q' => 'mcp'])->content)->toBe('hits: mcp');
});
