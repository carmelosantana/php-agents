<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Context;

use CarmeloSantana\PHPAgents\Contract\TokenCounterInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;

final class TiktokenCounter implements TokenCounterInterface
{
    private ?object $encoder = null;
    private HeuristicCounter $fallback;

    public function __construct(
        private readonly string $encodingName = 'cl100k_base',
    ) {
        $this->fallback = new HeuristicCounter();
        $this->initEncoder();
    }

    private function initEncoder(): void
    {
        if (!class_exists(\Yethee\Tiktoken\EncoderProvider::class)) {
            return;
        }

        try {
            $provider = new \Yethee\Tiktoken\EncoderProvider();
            $this->encoder = $provider->get($this->encodingName);
        } catch (\Throwable) {
            $this->encoder = null;
        }
    }

    public function count(string $text): int
    {
        if ($this->encoder === null) {
            return $this->fallback->count($text);
        }

        try {
            /** @phpstan-ignore-next-line */
            return count($this->encoder->encode($text));
        } catch (\Throwable) {
            return $this->fallback->count($text);
        }
    }

    public function countMessages(array $messages): int
    {
        if ($this->encoder === null) {
            return $this->fallback->countMessages($messages);
        }

        $tokens = 0;

        foreach ($messages as $message) {
            $tokens += 4;
            $content = $message->content();
            $tokens += is_string($content)
                ? $this->count($content)
                : $this->countMultimodal($content);
            $tokens += $this->count($message->role()->value);

            foreach ($message->toolCalls() as $toolCall) {
                $tokens += $this->count($toolCall->name);
                $tokens += $this->count(json_encode($toolCall->arguments) ?: '');
            }
        }

        $tokens += 2;

        return $tokens;
    }

    public function countTools(array $tools): int
    {
        $schema = json_encode(array_map(fn(ToolInterface $t) => $t->toFunctionSchema(), $tools));

        return $this->count($schema ?: '');
    }

    public function encoding(): string
    {
        return $this->encodingName;
    }

    /**
     * @param array<array<string, mixed>> $parts
     */
    private function countMultimodal(array $parts): int
    {
        $tokens = 0;

        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $tokens += $this->count($part['text']);
            }
            if (isset($part['image_url'])) {
                $tokens += 85;
            }
        }

        return $tokens;
    }
}
