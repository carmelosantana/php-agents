<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

final readonly class Response
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public string $content,
        public FinishReason $finishReason,
        public array $toolCalls = [],
        public string $model = '',
        public ?Usage $usage = null,
    ) {}
}
