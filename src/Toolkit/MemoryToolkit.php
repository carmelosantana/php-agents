<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Toolkit;

use CarmeloSantana\PHPAgents\Contract\MemoryInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Memory\MemoryEntry;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class MemoryToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly MemoryInterface $memory,
    ) {}

    public function tools(): array
    {
        return [
            $this->memorySaveTool(),
            $this->memoryLoadTool(),
            $this->memoryForgetTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<GUIDELINES
        <MEMORY-GUIDELINES>
        You have persistent memory across conversations.
        - Use memory_save to store important facts: user preferences, names, successful solutions, project knowledge.
        - Use memory_load to recall information. Search by description, not exact text.
        - Use memory_forget to remove outdated or incorrect memories.
        - Memory areas: "main" (facts/preferences), "solutions" (what worked), "context" (project knowledge).
        - Be selective — save what matters, not every detail.
        </MEMORY-GUIDELINES>
        GUIDELINES;
    }

    private function memorySaveTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_save',
            description: 'Save an important fact, preference, or solution to persistent memory. '
                . 'This persists across conversations.',
            parameters: [
                new StringParameter('text', 'The content to remember', required: true),
                new EnumParameter('area', 'Memory area', ['main', 'solutions', 'context'], required: false),
                new StringParameter('tags', 'Comma-separated tags for categorization', required: false),
            ],
            callback: function (array $input): ToolResult {
                $entry = new MemoryEntry(
                    content: $input['text'] ?? '',
                    area: $input['area'] ?? 'main',
                    metadata: ['tags' => $input['tags'] ?? ''],
                );
                $id = $this->memory->save($entry);

                return ToolResult::success("Memory saved (id: {$id})");
            },
        );
    }

    private function memoryLoadTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_load',
            description: 'Search your persistent memory for information. '
                . 'Describe what you are looking for — results are ranked by relevance.',
            parameters: [
                new StringParameter('query', 'What to search for', required: true),
                new NumberParameter('limit', 'Max results (default: 10)', required: false, integer: true),
            ],
            callback: function (array $input): ToolResult {
                $results = $this->memory->search(
                    $input['query'] ?? '',
                    limit: (int) ($input['limit'] ?? 10),
                );

                if (empty($results)) {
                    return ToolResult::success('No memories found matching your query.');
                }

                $formatted = array_map(
                    fn(MemoryEntry $e) => "[{$e->area}] {$e->content}",
                    $results,
                );

                return ToolResult::success(implode("\n\n", $formatted));
            },
        );
    }

    private function memoryForgetTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_forget',
            description: 'Remove outdated or incorrect memories. '
                . 'Describe what to forget — all matching memories will be deleted.',
            parameters: [
                new StringParameter('query', 'Description of memories to forget', required: true),
            ],
            callback: function (array $input): ToolResult {
                $count = $this->memory->forget($input['query'] ?? '');

                return ToolResult::success("Forgot {$count} memories.");
            },
        );
    }
}
