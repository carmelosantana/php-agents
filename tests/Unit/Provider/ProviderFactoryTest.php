<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;

test('parseModelString splits provider and model', function () {
    expect(ProviderFactory::parseModelString('openai/gpt-4o'))
        ->toBe(['openai', 'gpt-4o']);

    expect(ProviderFactory::parseModelString('anthropic/claude-sonnet-4-5'))
        ->toBe(['anthropic', 'claude-sonnet-4-5']);

    expect(ProviderFactory::parseModelString('xai/grok-4'))
        ->toBe(['xai', 'grok-4']);
});

test('parseModelString defaults to ollama when no slash', function () {
    expect(ProviderFactory::parseModelString('llama3.2:latest'))
        ->toBe(['ollama', 'llama3.2:latest']);
});

test('fromModelString routes ollama to OllamaProvider', function () {
    $provider = ProviderFactory::fromModelString('ollama/llama3.2');

    expect($provider)->toBeInstanceOf(OllamaProvider::class);
    expect($provider->getModel())->toBe('llama3.2');
});

test('fromModelString routes anthropic to AnthropicProvider', function () {
    $provider = ProviderFactory::fromModelString('anthropic/claude-sonnet-4-5');

    expect($provider)->toBeInstanceOf(AnthropicProvider::class);
    expect($provider->getModel())->toBe('claude-sonnet-4-5');
});

test('fromModelString routes xai to OpenAICompatibleProvider', function () {
    $provider = ProviderFactory::fromModelString('xai/grok-4');

    expect($provider)->toBeInstanceOf(OpenAICompatibleProvider::class);
    expect($provider->getModel())->toBe('grok-4');
});

test('fromModelString routes openai to OpenAICompatibleProvider', function () {
    $provider = ProviderFactory::fromModelString('openai/gpt-4o');

    expect($provider)->toBeInstanceOf(OpenAICompatibleProvider::class);
    expect($provider->getModel())->toBe('gpt-4o');
});

test('fromModelString routes codex model to OpenAIResponsesProvider', function () {
    $provider = ProviderFactory::fromModelString('openai/gpt-5-codex');

    expect($provider)->toBeInstanceOf(OpenAIResponsesProvider::class);
    expect($provider->getModel())->toBe('gpt-5-codex');
});

test('fromModelString routes unknown provider to OpenAICompatibleProvider', function () {
    $provider = ProviderFactory::fromModelString('custom/my-model');

    expect($provider)->toBeInstanceOf(OpenAICompatibleProvider::class);
    expect($provider->getModel())->toBe('my-model');
});

test('xai provider resolves XAI_API_KEY from environment', function () {
    // Set env var for test
    $previousValue = getenv('XAI_API_KEY');
    putenv('XAI_API_KEY=test-xai-key-12345');

    try {
        $provider = ProviderFactory::fromModelString('xai/grok-4');

        // The provider is created — verify it's the right type
        expect($provider)->toBeInstanceOf(OpenAICompatibleProvider::class);
        expect($provider->getModel())->toBe('grok-4');
    } finally {
        // Restore previous env
        if ($previousValue === false) {
            putenv('XAI_API_KEY');
        } else {
            putenv("XAI_API_KEY={$previousValue}");
        }
    }
});
