<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Context;

use CarmeloSantana\PHPAgents\Contract\TokenCounterInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;

final class HeuristicCounter implements TokenCounterInterface
{
    public function __construct(
        private readonly float $charsPerToken = 4.0,
    ) {}

    public function count(string $text): int
    {
        return (int) ceil(mb_strlen($text) / $this->charsPerToken);
    }

    public function countMessages(array $messages): int
    {
        $tokens = 0;

        foreach ($messages as $message) {
            $tokens += 4;
            $content = $message->content();
            $tokens += is_string($content)
                ? $this->count($content)
                : $this->count(json_encode($content) ?: '');
        }

        return $tokens;
    }

    public function countTools(array $tools): int
    {
        $schema = json_encode(array_map(fn(ToolInterface $t) => $t->toFunctionSchema(), $tools));

        return $this->count($schema ?: '');
    }

    public function encoding(): string
    {
        return 'heuristic';
    }
}
