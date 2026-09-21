<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\McpToolName;

// The vectors were computed with Alpaca Bot's Toolkit\ToolName::fit() (plan 2026-09-15, Task 22).

test('fit leaves a name that already fits unchanged and marks every other', function (string $raw, string $expected) {
    expect(McpToolName::fit($raw))->toBe($expected)
        ->and(strlen(McpToolName::fit($raw)))->toBeLessThanOrEqual(McpToolName::MAX);
})->with([
    'fits' => ['trk__search', 'trk__search'],
    'dot' => ['trk__repo.search', 'trk__repo_search_45ce4942'],
    'no merge with the dotted one' => ['trk__repo_search', 'trk__repo_search'],
    'digit first' => ['9lives', '_9lives_bc867356'],
    'too long' => [str_repeat('a', 70), str_repeat('a', 55) . '_6bd5e503'],
]);
