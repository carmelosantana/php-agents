<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Enum\Role;

test('empty conversation has zero messages', function () {
    $conversation = new Conversation();

    expect($conversation->count())->toBe(0);
    expect($conversation->messages())->toBe([]);
});

test('add appends messages', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('hello'));
    $conversation->add(new AssistantMessage('hi'));

    expect($conversation->count())->toBe(2);
});

test('first returns first message', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('system'));
    $conversation->add(new UserMessage('user'));

    $first = $conversation->first();
    expect($first)->not->toBeNull();
    expect($first->role())->toBe(Role::System);
});

test('last returns last message', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('hello'));
    $conversation->add(new AssistantMessage('world'));

    $last = $conversation->last();
    expect($last)->not->toBeNull();
    expect($last->role())->toBe(Role::Assistant);
});

test('first and last return null on empty conversation', function () {
    $conversation = new Conversation();

    expect($conversation->first())->toBeNull();
    expect($conversation->last())->toBeNull();
});

test('filter returns messages by role', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('sys'));
    $conversation->add(new UserMessage('u1'));
    $conversation->add(new AssistantMessage('a1'));
    $conversation->add(new UserMessage('u2'));

    $userMessages = $conversation->filter(Role::User);
    expect($userMessages)->toHaveCount(2);
});

test('toArray converts all messages', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('hello'));

    $array = $conversation->toArray();
    expect($array)->toBeArray();
    expect($array[0]['role'])->toBe('user');
    expect($array[0]['content'])->toBe('hello');
});

test('estimateTokens returns positive for non-empty conversation', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('This is a test message with some content.'));

    expect($conversation->estimateTokens())->toBeGreaterThan(0);
});

test('estimateTokens counts a multimodal message with invalid UTF-8 text', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage([
        ['type' => 'text', 'text' => "bad\xff" . str_repeat('t', 4_000_000)],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
    ]));

    expect($conversation->estimateTokens())->toBeGreaterThan(1_000_000);
});

// An image's base64 is not text the model reads as tokens: each image part counts as a
// flat Conversation::IMAGE_TOKENS, however many bytes it carries.

function estimateOf(string|array $content): int
{
    $conversation = new Conversation();
    $conversation->add(new UserMessage($content));

    return $conversation->estimateTokens();
}

test('estimateTokens counts an image part as IMAGE_TOKENS, not its base64 length', function (int $bytes) {
    $text = ['type' => 'text', 'text' => 'Describe this picture.'];
    $image = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . str_repeat('A', $bytes)]];

    expect(estimateOf([$text, $image]))->toBe(estimateOf([$text]) + Conversation::IMAGE_TOKENS);
})->with(['tiny' => 4, '4 MiB' => 4 * 1024 * 1024]);

test('estimateTokens counts each image part once', function () {
    $text = ['type' => 'text', 'text' => 'Compare these.'];
    $image = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . str_repeat('B', 100_000)]];

    expect(estimateOf([$text, $image, $image]))->toBe(estimateOf([$text]) + 2 * Conversation::IMAGE_TOKENS);
});

test('estimateTokens counts an Anthropic-style image block as an image', function () {
    $text = ['type' => 'text', 'text' => 'What is this?'];
    $image = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => str_repeat('C', 100_000)]];

    expect(estimateOf([$text, $image]))->toBe(estimateOf([$text]) + Conversation::IMAGE_TOKENS);
});

test('IMAGE_TOKENS is a small fixed count', function () {
    expect(Conversation::IMAGE_TOKENS)->toBe(1_000);
});

test('estimateTokens counts tool calls whose arguments carry invalid UTF-8', function () {
    $conversation = new Conversation();
    $conversation->add(new AssistantMessage('', [new \CarmeloSantana\PHPAgents\Tool\ToolCall('c', 'first', ['payload' => "bad\xff" . str_repeat('p', 4_000)])]));

    expect($conversation->estimateTokens())->toBeGreaterThan(1_000);
});
