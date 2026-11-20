<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\McpClient;

// clientInfo reports McpClient::CLIENT_VERSION; it must name the release the code ships in,
// which is the newest "Upgrading to X.Y" section of docs/UPGRADING.md.

test('CLIENT_VERSION is in the release line of the newest UPGRADING section', function () {
    $upgrading = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');
    preg_match('/^## Upgrading to (\d+\.\d+)/m', $upgrading, $m);

    expect($m[1] ?? null)->not->toBeNull()
        ->and(McpClient::CLIENT_VERSION)->toStartWith(($m[1] ?? '') . '.');
});
