<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolCallParser;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

// alpaca-bot uses LlamaCppToolCallParser to recover tool calls a model writes as text. These tests
// pin its namespace and public API: parse(string $content, string $parserMode): ToolCall[].

test('a tool_calls array becomes ToolCalls with their ids, names and arguments', function () {
    $calls = (new LlamaCppToolCallParser())->parse(
        '{"tool_calls":[{"id":"call_a","name":"add","arguments":{"a":1,"b":2}},{"id":"call_b","function":{"name":"echo","arguments":"{\"text\":\"hi\"}"}}]}',
        'json',
    );

    expect($calls)->toHaveCount(2)
        ->and($calls[0])->toBeInstanceOf(ToolCall::class)
        ->and($calls[0]->id)->toBe('call_a')
        ->and($calls[0]->name)->toBe('add')
        ->and($calls[0]->arguments)->toBe(['a' => 1, 'b' => 2])
        ->and($calls[1]->id)->toBe('call_b')
        ->and($calls[1]->name)->toBe('echo')
        ->and($calls[1]->arguments)->toBe(['text' => 'hi']);
});

test('a single call object, toolCalls and calls keys, and input are accepted', function (string $content) {
    $calls = (new LlamaCppToolCallParser())->parse($content, 'json');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->name)->toBe('add')
        ->and($calls[0]->arguments)->toBe(['a' => 1]);
})->with([
    'single object' => '{"name":"add","arguments":{"a":1}}',
    'toolCalls' => '{"toolCalls":[{"name":"add","arguments":{"a":1}}]}',
    'calls' => '{"calls":[{"name":"add","input":{"a":1}}]}',
]);

test('a json code fence is stripped', function () {
    $calls = (new LlamaCppToolCallParser())->parse("```json\n{\"name\":\"add\",\"arguments\":{}}\n```", 'json');

    expect($calls[0]->name)->toBe('add')
        ->and($calls[0]->arguments)->toBe([]);
});

test('a call without an id gets a stable generated one', function () {
    $parser = new LlamaCppToolCallParser();
    $first = $parser->parse('{"name":"add","arguments":{"a":1}}', 'json');
    $again = $parser->parse('{"name":"add","arguments":{"a":1}}', 'json');

    expect($first[0]->id)->toMatch('/^call_[0-9a-f]{12}$/')
        ->and($again[0]->id)->toBe($first[0]->id);
});

test('an unsupported parser mode throws InvalidArgumentException', function () {
    (new LlamaCppToolCallParser())->parse('{}', 'xml');
})->throws(InvalidArgumentException::class, 'Unsupported llama.cpp tool parser mode: xml');

test('malformed payloads throw RuntimeException', function (string $content, string $message) {
    expect(fn() => (new LlamaCppToolCallParser())->parse($content, 'json'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'empty' => ['  ', 'Tool call payload was empty.'],
    'not json' => ['hello', 'Tool call payload must decode to an object.'],
    'no calls' => ['{"answer":1}', 'Tool call payload did not contain a tool_calls array.'],
    'entry not an object' => ['{"tool_calls":[1]}', 'Each tool call payload entry must be an object.'],
    'no name' => ['{"tool_calls":[{"arguments":{}}]}', 'Tool call payload did not contain a valid tool name.'],
    'arguments not an object' => ['{"name":"add","arguments":"[1"}', "Tool call 'add' did not contain an object of arguments."],
]);
