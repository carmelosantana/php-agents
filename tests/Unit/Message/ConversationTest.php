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
