<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolDocumentationInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Prompt\SystemPrompt;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('system prompt with tools renders parameter constraints', function () {
    $tool = new Tool(
        name: 'create_project',
        description: 'Create a project',
        parameters: [
            new StringParameter('slug', 'Lowercase slug', pattern: '/^[a-z-]+$/', maxLength: 32),
            new EnumParameter('status', 'Project status', ['active', 'archived'], required: false),
            new NumberParameter('count', 'Count', required: false, integer: true, minimum: 1, maximum: 3),
        ],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );

    $prompt = SystemPrompt::withTools([$tool], SystemPrompt::withIdentity('Identity'));
    $rendered = SystemPrompt::render($prompt);

    expect($rendered)->toContain('accepted values: active, archived');
    expect($rendered)->toContain('max length: 32');
    expect($rendered)->toContain('pattern: /^[a-z-]+$/');
    expect($rendered)->toContain('integer');
    expect($rendered)->toContain('min: 1');
    expect($rendered)->toContain('max: 3');
});

test('system prompt renders optional use-when guidance and examples for documented tools', function () {
    $tool = new class implements ToolInterface, ToolDocumentationInterface {
        public function name(): string
        {
            return 'tool_search';
        }

        public function description(): string
        {
            return 'Search available tools.';
        }

        public function parameters(): array
        {
            return [new StringParameter('query', 'Search keywords', required: true)];
        }

        public function execute(array $input): ToolResult
        {
            return ToolResult::success('ok');
        }

        public function toFunctionSchema(): array
        {
            return (new Tool(
                name: $this->name(),
                description: $this->description(),
                parameters: $this->parameters(),
                callback: fn(array $input): ToolResult => $this->execute($input),
            ))->toFunctionSchema();
        }

        public function useWhen(): ?string
        {
            return 'You know the capability you need but not the exact tool name.';
        }

        public function examples(): array
        {
            return ['query: "file search"', 'query: "git diff"'];
        }
    };

    $prompt = SystemPrompt::withTools([$tool], SystemPrompt::withIdentity('Identity'));
    $rendered = SystemPrompt::render($prompt);

    expect($rendered)->toContain('Use when: You know the capability you need but not the exact tool name.');
    expect($rendered)->toContain('Examples:');
    expect($rendered)->toContain('query: "file search"');
    expect($rendered)->toContain('query: "git diff"');
});