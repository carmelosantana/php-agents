<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

final readonly class Output
{
    /**
     * @param ToolResult[] $toolResults
     */
    public function __construct(
        public string $content,
        public array $toolResults = [],
        public ?Usage $usage = null,
        public string $model = '',
        public int $iterations = 0,
        public ?Conversation $conversation = null,
    ) {}
}
