<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Mcp\Internal\ResultMapper;

test('text blocks are joined with a blank line and every block is kept in metadata', function () {
    $blocks = [['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two']];
    $result = ResultMapper::toToolResult(['content' => $blocks], 1000);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toBe("one\n\ntwo")
        ->and($result->metadata['mcp'])->toBe(['content' => $blocks, 'isError' => false, 'bytes' => 8, 'truncated' => false]);
});

test('non-text blocks become one-line placeholders', function () {
    $result = ResultMapper::toToolResult(['content' => [
        ['type' => 'image', 'data' => base64_encode(str_repeat('p', 48)), 'mimeType' => 'image/png'],
        ['type' => 'audio', 'data' => base64_encode('abc'), 'mimeType' => 'audio/wav'],
        ['type' => 'resource_link', 'uri' => 'file:///r.txt', 'name' => 'r.txt'],
        ['type' => 'resource', 'resource' => ['uri' => 'file:///b.bin', 'mimeType' => 'application/octet-stream', 'blob' => base64_encode('12345')]],
        ['type' => 'resource', 'resource' => ['uri' => 'file:///t.txt', 'text' => 'inline text']],
        ['type' => 'hologram'],
    ]], 1000);

    expect($result->content)->toBe(implode("\n\n", [
        '[image image/png, 48 bytes]',
        '[audio audio/wav, 3 bytes]',
        '[resource_link r.txt file:///r.txt]',
        '[resource file:///b.bin (application/octet-stream), 5 bytes]',
        "[resource file:///t.txt]\ninline text",
        '[hologram block]',
    ]));
});

test('structuredContent becomes pretty JSON only when there is no text block', function () {
    $structured = ['temp' => 21.5, 'city' => 'Newburgh'];
    $only = ResultMapper::toToolResult(['content' => [], 'structuredContent' => $structured], 1000);
    $withText = ResultMapper::toToolResult(['content' => [['type' => 'text', 'text' => '21.5 in Newburgh']], 'structuredContent' => $structured], 1000);

    expect($only->content)->toBe("{\n    \"temp\": 21.5,\n    \"city\": \"Newburgh\"\n}")
        ->and($only->mimeType)->toBe('application/json')
        ->and($only->metadata['mcp']['structuredContent'])->toBe($structured)
        ->and($withText->content)->toBe('21.5 in Newburgh')
        ->and($withText->mimeType)->toBeNull()
        ->and($withText->metadata['mcp']['structuredContent'])->toBe($structured);
});

test('isError becomes an error result with the mcp_tool_error code', function () {
    $withText = ResultMapper::toToolResult(['isError' => true, 'content' => [['type' => 'text', 'text' => 'no such issue']]], 1000);
    $bare = ResultMapper::toToolResult(['isError' => true, 'content' => []], 1000);

    expect($withText->status)->toBe(ToolResultStatus::Error)
        ->and($withText->content)->toBe('no such issue')
        ->and($withText->errorCode)->toBe('mcp_tool_error')
        ->and($withText->metadata['mcp']['isError'])->toBeTrue()
        ->and($bare->content)->toBe('The tool reported an error.');
});

test('content past the cap is cut on a UTF-8 boundary and says so', function () {
    $text = str_repeat('é', 10); // 20 bytes
    $result = ResultMapper::toToolResult(['content' => [['type' => 'text', 'text' => $text]]], 7);

    expect($result->content)->toBe(str_repeat('é', 3) . "\n[truncated: 6 of 20 bytes]")
        ->and($result->metadata['mcp']['truncated'])->toBeTrue()
        ->and($result->metadata['mcp']['bytes'])->toBe(20)
        ->and(mb_check_encoding($result->content, 'UTF-8'))->toBeTrue();
});

test('truncated JSON is not labelled as JSON', function () {
    $payload = ['content' => [], 'structuredContent' => ['k' => str_repeat('v', 100)]];
    $untruncated = ResultMapper::toToolResult($payload, 1000);
    $result = ResultMapper::toToolResult($payload, 20);

    expect($untruncated->mimeType)->toBe('application/json')
        ->and($untruncated->metadata['mcp']['truncated'])->toBeFalse()
        ->and($result->mimeType)->toBeNull()
        ->and($result->metadata['mcp']['truncated'])->toBeTrue();
});

test('an empty or malformed result is an empty success', function () {
    expect(ResultMapper::toToolResult([], 10)->content)->toBe('')
        ->and(ResultMapper::toToolResult(['content' => 'nope'], 10)->content)->toBe('')
        ->and(ResultMapper::toToolResult(['content' => ['x', ['type' => 'text']]], 10)->content)->toBe('');
});

test('structuredContent joined with a non-text block is not typed as JSON', function () {
    $structured = ['temp' => 21.5];
    $result = ResultMapper::toToolResult(['content' => [
        ['type' => 'image', 'data' => base64_encode(str_repeat('p', 48)), 'mimeType' => 'image/png'],
    ], 'structuredContent' => $structured], 1000);

    expect($result->mimeType)->toBeNull()
        ->and($result->content)->toBe("{\n    \"temp\": 21.5\n}\n\n[image image/png, 48 bytes]")
        ->and($result->metadata['mcp']['structuredContent'])->toBe($structured);
});

test('an embedded resource with neither text nor blob is a bare placeholder', function () {
    $result = ResultMapper::toToolResult(['content' => [
        ['type' => 'resource', 'resource' => ['uri' => 'file:///empty.dat', 'mimeType' => 'application/octet-stream']],
    ]], 1000);

    expect($result->content)->toBe('[resource file:///empty.dat]');
});

test('every field a placeholder interpolates has a fallback', function (array $block, string $expected) {
    expect(ResultMapper::toToolResult(['content' => [$block]], 1000)->content)->toBe($expected);
})->with([
    'mime and size' => [['type' => 'image'], '[image unknown, 0 bytes]'],
    'size, on a base64 that does not decode' => [['type' => 'audio', 'mimeType' => 'audio/wav', 'data' => '!!!'], '[audio audio/wav, 0 bytes]'],
    'type' => [['label' => 'nope'], '[unknown block]'],
    'name and uri, both spaces kept' => [['type' => 'resource_link'], '[resource_link  ]'],
    'name only' => [['type' => 'resource_link', 'uri' => 'file:///a.txt'], '[resource_link  file:///a.txt]'],
    'uri, on a blob resource' => [['type' => 'resource', 'resource' => ['blob' => 'eHl6']], '[resource  (unknown), 3 bytes]'],
    'uri, on a bare resource' => [['type' => 'resource', 'resource' => []], '[resource ]'],
    'the resource object itself' => [['type' => 'resource'], '[resource ]'],
]);

test('structuredContent json_encode refuses is an empty object, not an empty string', function () {
    // Reachable from the wire: a server's 1e999 overflows to INF in json_decode, and
    // json_encode refuses INF whatever the flags.
    $structured = json_decode('{"overflow":1e999}', true);

    $result = ResultMapper::toToolResult(['content' => [], 'structuredContent' => $structured], 1000);

    expect($result->content)->toBe('{}')
        ->and($result->mimeType)->toBe('application/json');
});
