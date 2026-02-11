<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * Abstraction for LLM API communication.
 */
interface ProviderInterface
{
    /**
     * Send a chat completion request.
     *
     * @param MessageInterface[] $messages Conversation messages
     * @param ToolInterface[] $tools Available tools
     * @param array<string, mixed> $options Provider-specific options
     */
    public function chat(array $messages, array $tools = [], array $options = []): Response;

    /**
     * Stream a chat completion.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<Response>
     */
    public function stream(array $messages, array $tools = [], array $options = []): iterable;

    /**
     * Request structured output matching a JSON Schema.
     *
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     */
    public function structured(array $messages, string $schema, array $options = []): mixed;

    /**
     * List available models from this provider.
     *
     * @return ModelDefinition[]
     */
    public function models(): array;

    /**
     * Check if the provider endpoint is reachable.
     */
    public function isAvailable(): bool;

    /**
     * The model identifier currently configured.
     */
    public function getModel(): string;

    /**
     * Set the model for this provider.
     */
    public function withModel(string $model): static;
}
