<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Message;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Enum\Role;

final class Conversation
{
    /** @var MessageInterface[] */
    private array $messages = [];

    public function add(MessageInterface $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return MessageInterface[]
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return MessageInterface[]
     */
    public function all(): array
    {
        return $this->messages;
    }

    public function last(): ?MessageInterface
    {
        if (empty($this->messages)) {
            return null;
        }

        return $this->messages[array_key_last($this->messages)];
    }

    public function first(): ?MessageInterface
    {
        if (empty($this->messages)) {
            return null;
        }

        return $this->messages[array_key_first($this->messages)];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn(MessageInterface $m) => $m->toArray(), $this->messages);
    }

    /**
     * Filter messages by role.
     *
     * @return MessageInterface[]
     */
    public function filter(Role $role): array
    {
        return array_filter($this->messages, fn(MessageInterface $m) => $m->role() === $role);
    }

    /**
     * Estimate total tokens (rough: 1 token ≈ 4 chars).
     */
    public function estimateTokens(): int
    {
        $chars = 0;
        foreach ($this->messages as $msg) {
            $content = $msg->content();
            $chars += is_string($content) ? strlen($content) : strlen(json_encode($content) ?: '');

            // Account for tool call schemas in assistant messages
            if (!empty($msg->toolCalls())) {
                $chars += strlen(json_encode(
                    array_map(fn($tc) => ['id' => $tc->id, 'name' => $tc->name, 'arguments' => $tc->arguments], $msg->toolCalls()),
                ) ?: '');
            }
        }

        return (int) ceil($chars / 4);
    }

    /**
     * Soft-trim tool result content to reduce token count.
     *
     * Keeps the first $headChars and last $tailChars of each tool result,
     * replacing the middle with a trimmed marker. User and assistant messages
     * are never modified (following OpenClaw's proven pattern).
     *
     * Returns a new Conversation — the original is not mutated.
     */
    public function trimToolResults(int $maxChars = 500, int $tailChars = 100): self
    {
        $headChars = $maxChars - $tailChars;
        if ($headChars < 50) {
            $headChars = 50;
        }

        $trimmed = new self();

        foreach ($this->messages as $msg) {
            if ($msg->role() !== Role::Tool) {
                $trimmed->add($msg);
                continue;
            }

            $content = $msg->content();
            if (!is_string($content) || strlen($content) <= $maxChars) {
                $trimmed->add($msg);
                continue;
            }

            // Soft-trim: keep head + tail with marker
            $head = substr($content, 0, $headChars);
            $tail = substr($content, -$tailChars);
            $originalLen = strlen($content);
            $trimmedContent = "{$head}\n\n[... trimmed {$originalLen} chars to {$maxChars} ...]\n\n{$tail}";

            $trimmed->add(new ToolResultMessage(
                (new \CarmeloSantana\PHPAgents\Tool\ToolResult(
                    \CarmeloSantana\PHPAgents\Enum\ToolResultStatus::Success,
                    $trimmedContent,
                ))->withCallId($msg->toolCallId() ?? ''),
            ));
        }

        return $trimmed;
    }

    /**
     * Drop oldest user turns, keeping the last $keepTurns user messages
     * and all associated assistant/tool messages that follow each kept user message.
     *
     * System messages are always preserved. Returns a new Conversation.
     */
    public function dropOldestTurns(int $keepTurns): self
    {
        if ($keepTurns <= 0) {
            $keepTurns = 1;
        }

        // Find all user message indices
        $userIndices = [];
        foreach ($this->messages as $i => $msg) {
            if ($msg->role() === Role::User) {
                $userIndices[] = $i;
            }
        }

        // If we have fewer user turns than the limit, return a copy
        if (count($userIndices) <= $keepTurns) {
            $copy = new self();
            foreach ($this->messages as $msg) {
                $copy->add($msg);
            }
            return $copy;
        }

        // Find the cut point: keep from the ($keepTurns)th-from-last user message onward
        $cutIndex = $userIndices[count($userIndices) - $keepTurns];

        $result = new self();

        // Always keep system messages
        foreach ($this->messages as $i => $msg) {
            if ($msg->role() === Role::System) {
                $result->add($msg);
            }
        }

        // Add messages from cut point onward (skip system — already added)
        for ($i = $cutIndex; $i < count($this->messages); $i++) {
            if ($this->messages[$i]->role() !== Role::System) {
                $result->add($this->messages[$i]);
            }
        }

        return $result;
    }

    /**
     * Remove orphaned tool result messages whose corresponding assistant
     * message with tool_calls was dropped.
     *
     * This prevents API errors (especially Anthropic) when tool_results
     * reference tool_call IDs that no longer exist in the conversation.
     *
     * Returns a new Conversation.
     */
    public function repairToolPairing(): self
    {
        // Collect all tool_call IDs from assistant messages
        $validCallIds = [];
        foreach ($this->messages as $msg) {
            if ($msg->role() === Role::Assistant) {
                foreach ($msg->toolCalls() as $tc) {
                    $validCallIds[$tc->id] = true;
                }
            }
        }

        $result = new self();

        foreach ($this->messages as $msg) {
            if ($msg->role() === Role::Tool) {
                $callId = $msg->toolCallId();
                // Drop orphaned tool results
                if ($callId !== null && !isset($validCallIds[$callId])) {
                    continue;
                }
            }
            $result->add($msg);
        }

        return $result;
    }

    /**
     * Merge consecutive messages with the same role into single messages.
     *
     * After pruning operations (dropOldestTurns, repairToolPairing), the
     * conversation may contain consecutive same-role messages. Some providers
     * (notably Anthropic) reject these. This method merges them by concatenating
     * text content with newlines.
     *
     * Tool messages are never merged — each tool result must correspond to
     * exactly one tool_use_id. Assistant messages with tool calls are also
     * kept separate to preserve their tool_call structure.
     *
     * Returns a new Conversation.
     */
    public function mergeConsecutiveRoles(): self
    {
        $result = new self();
        $lastMsg = null;

        foreach ($this->messages as $msg) {
            // Never merge tool result messages — they have unique tool_call_ids
            if ($msg->role() === Role::Tool) {
                $result->add($msg);
                $lastMsg = $msg;
                continue;
            }

            // Never merge assistant messages with tool calls — structure matters
            if ($msg->role() === Role::Assistant && !empty($msg->toolCalls())) {
                $result->add($msg);
                $lastMsg = $msg;
                continue;
            }

            // Merge consecutive same-role text-only messages
            if (
                $lastMsg !== null
                && $lastMsg->role() === $msg->role()
                && empty($lastMsg->toolCalls())
            ) {
                // Remove the last message and replace with merged content
                $messages = $result->messages();
                $lastContent = is_string($lastMsg->content()) ? $lastMsg->content() : (json_encode($lastMsg->content()) ?: '');
                $newContent = is_string($msg->content()) ? $msg->content() : (json_encode($msg->content()) ?: '');
                $mergedContent = $lastContent . "\n\n" . $newContent;

                // Rebuild the conversation without the last message
                $result = new self();
                for ($i = 0; $i < count($messages) - 1; $i++) {
                    $result->add($messages[$i]);
                }

                // Add merged message based on role.
                // At this point $msg is User, Assistant (text-only), or System
                // since Tool and Assistant-with-tool-calls are handled above.
                $merged = match ($msg->role()) {
                    Role::User => new UserMessage($mergedContent),
                    Role::Assistant => new AssistantMessage($mergedContent),
                    default => new SystemMessage($mergedContent),
                };
                $result->add($merged);
                $lastMsg = $merged;
                continue;
            }

            $result->add($msg);
            $lastMsg = $msg;
        }

        return $result;
    }

    /**
     * Progressively prune the conversation to fit within a token budget.
     *
     * Strategy (inspired by OpenClaw):
     * 1. Soft-trim tool results (keep head + tail)
     * 2. Drop oldest turns if still over budget
     * 3. Repair orphaned tool result pairing
     *
     * A safety margin (default 20%) accounts for heuristic inaccuracy.
     * System messages and the most recent user turn are never dropped.
     *
     * Returns a new Conversation.
     */
    public function fitWithinBudget(int $maxTokens, int $safetyMarginPercent = 20): self
    {
        $effectiveBudget = (int) ($maxTokens * (1 - $safetyMarginPercent / 100));

        $result = clone $this;

        // Step 1: Soft-trim tool results if over budget
        if ($result->estimateTokens() > $effectiveBudget) {
            $result = $result->trimToolResults(500, 100);
        }

        // Step 2: Progressively drop oldest turns if still over budget
        $userCount = count($result->filter(Role::User));
        while ($result->estimateTokens() > $effectiveBudget && $userCount > 1) {
            $userCount--;
            $result = $result->dropOldestTurns($userCount);
        }

        // Step 3: More aggressive tool trimming if still over budget
        if ($result->estimateTokens() > $effectiveBudget) {
            $result = $result->trimToolResults(200, 50);
        }

        // Step 4: Repair any orphaned tool results from dropped turns
        $result = $result->repairToolPairing();

        // Step 5: Merge consecutive same-role messages that may result from pruning
        $result = $result->mergeConsecutiveRoles();

        return $result;
    }

    /**
     * Create a shallow clone with a copy of the messages array.
     */
    public function __clone(): void
    {
        // Messages are readonly objects — shallow copy of the array is sufficient
        $this->messages = [...$this->messages];
    }
}
