<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Internal\HttpReply;
use CarmeloSantana\PHPAgents\Mcp\Internal\SseReader;
use CarmeloSantana\PHPAgents\Mcp\McpProtocolException;

function reply(int $status, string $body, string $type = 'application/json', array $extra = []): HttpReply
{
    return new HttpReply('tools/list', $status, ['content-type' => [$type]] + $extra, $body);
}

test('a JSON response with the right id is the message', function () {
    expect(reply(200, '{"jsonrpc":"2.0","id":3,"result":{"tools":[]}}')->message(3))
        ->toBe(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => []]]);
});

test('a 2xx JSON body that is not the response to this id is a protocol error', function () {
    expect(fn () => reply(200, '{"jsonrpc":"2.0","id":4,"result":{}}')->message(3))->toThrow(McpProtocolException::class)
        ->and(fn () => reply(200, '{"jsonrpc":"2.0","id":3,"method":"ping"}')->message(3))->toThrow(McpProtocolException::class)
        ->and(fn () => reply(200, 'not json')->message(3))->toThrow(McpProtocolException::class)
        ->and(fn () => reply(200, '{"id":3}', 'text/html')->message(3))->toThrow(McpProtocolException::class);
});

test('an empty body has no message', function () {
    expect(reply(202, '')->message(1))->toBeNull()
        ->and(reply(400, '  ')->message(1))->toBeNull();
});

test('an error body is read whatever its id, and a non-JSON error body has no message', function () {
    expect(reply(400, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"x"}}')->message(9)['error']['code'])->toBe(-32600)
        ->and(reply(404, '<html>nope</html>', 'text/html')->message(9))->toBeNull()
        ->and(reply(400, '{"hello":"world"}')->message(9))->toBeNull();
});

test('an event stream yields the response for this id, skipping comments, notifications and server requests', function () {
    $body = ": hi\n\n"
        . "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\n"
        . "data: {\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"ping\"}\n\n"
        . "event: message\r\ndata: {\"jsonrpc\":\"2.0\",\r\ndata: \"id\":5,\"result\":{\"ok\":true}}\r\n\r\n";

    expect(reply(200, $body, 'text/event-stream; charset=utf-8')->message(5))->toBe(['jsonrpc' => '2.0', 'id' => 5, 'result' => ['ok' => true]])
        ->and(SseReader::find($body, 6))->toBeNull();
});

test('an SSE frame carrying a null method is still carrying a method, and is skipped', function () {
    $body = "data: {\"jsonrpc\":\"2.0\",\"id\":7,\"method\":null}\n\n"
        . "data: {\"jsonrpc\":\"2.0\",\"id\":7,\"result\":{\"ok\":true}}\n\n";

    expect(SseReader::find($body, 7))->toBe(['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]]);
});

test('a 2xx JSON body carrying a null method is not the response to this id', function () {
    expect(fn () => reply(200, '{"jsonrpc":"2.0","id":7,"method":null}')->message(7))->toThrow(McpProtocolException::class);
});

test('an error body carrying a null result is a message, not an absent one', function () {
    expect(reply(400, '{"jsonrpc":"2.0","id":7,"result":null}')->message(7))
        ->toBe(['jsonrpc' => '2.0', 'id' => 7, 'result' => null]);
});

test('headers are read case-insensitively', function () {
    $reply = new HttpReply('initialize', 200, ['mcp-session-id' => ['abc']], '');

    expect($reply->header('Mcp-Session-Id'))->toBe('abc')
        ->and($reply->header('missing'))->toBeNull()
        ->and($reply->isSuccess())->toBeTrue();
});
