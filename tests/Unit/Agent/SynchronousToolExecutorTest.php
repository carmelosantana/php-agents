<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\SynchronousToolExecutor;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('implements ToolExecutorInterface', function () {
    $executor = new SynchronousToolExecutor();

    expect($executor)->toBeInstanceOf(ToolExecutorInterface::class);
});

test('delegates to tool execute with correct arguments', function () {
    $executor = new SynchronousToolExecutor();
    $expectedResult = ToolResult::success('test output');
    $expectedArgs = ['foo' => 'bar', 'count' => 42];

    $tool = new class ($expectedResult, $expectedArgs) implements ToolInterface {
        private bool $called = false;

        public function __construct(
            private readonly ToolResult $result,
            private readonly array $expectedArgs,
        ) {}

        public function name(): string
        {
            return 'test_tool';
        }

        public function description(): string
        {
            return 'A test tool';
        }

        public function parameters(): array
        {
            return [];
        }

        public function execute(array $input): ToolResult
        {
            $this->called = true;
            // Verify we received the correct arguments
            if ($input !== $this->expectedArgs) {
                return ToolResult::error('Arguments mismatch');
            }
            return $this->result;
        }

        public function toFunctionSchema(): array
        {
            return [];
        }

        public function wasCalled(): bool
        {
            return $this->called;
        }
    };

    $result = $executor->execute($tool, $expectedArgs);

    expect($result->content)->toBe('test output');
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($tool->wasCalled())->toBeTrue();
});

test('propagates exceptions from tool', function () {
    $executor = new SynchronousToolExecutor();

    $tool = new class implements ToolInterface {
        public function name(): string
        {
            return 'failing_tool';
        }

        public function description(): string
        {
            return 'A tool that fails';
        }

        public function parameters(): array
        {
            return [];
        }

        public function execute(array $input): ToolResult
        {
            throw new \RuntimeException('Tool failed');
        }

        public function toFunctionSchema(): array
        {
            return [];
        }
    };

    expect(fn() => $executor->execute($tool, []))->toThrow(\RuntimeException::class, 'Tool failed');
});
