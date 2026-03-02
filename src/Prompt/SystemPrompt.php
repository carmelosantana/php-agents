<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Prompt;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

final class SystemPrompt
{
    private string $identity = '';
    private string $instructions = '';
    private string $tools = '';
    private string $iterationBudget = '';
    private string $guidelines = '';

    /**
     * Set identity/instructions section.
     */
    public static function withIdentity(string $instructions): self
    {
        $prompt = new self();
        $prompt->identity = $instructions;

        return $prompt;
    }

    /**
     * Add instructions to an existing prompt.
     */
    public static function withInstructions(string $instructions, self $prompt): self
    {
        $new = clone $prompt;
        $new->instructions = $instructions;

        return $new;
    }

    /**
     * Inject tool documentation.
     *
     * @param ToolInterface[] $tools
     */
    public static function withTools(array $tools, self $prompt): self
    {
        $new = clone $prompt;
        $lines = ["## Available Tools\n"];

        foreach ($tools as $tool) {
            $lines[] = "### {$tool->name()}";
            $lines[] = $tool->description();

            $params = $tool->parameters();
            if (!empty($params)) {
                $lines[] = "Parameters:";
                foreach ($params as $param) {
                    $req = $param->required ? '(required)' : '(optional)';
                    $lines[] = "  - `{$param->name}` {$req}: {$param->description}";
                }
            }
            $lines[] = '';
        }

        $new->tools = implode("\n", $lines);

        return $new;
    }

    /**
     * Inject iteration budget awareness into the prompt.
     *
     * When the agent has a finite iteration limit, this section tells it
     * how many iterations are available so it can manage resources wisely.
     * A value of 0 (unlimited) omits the section entirely.
     */
    public static function withIterationBudget(int $maxIterations, self $prompt): self
    {
        if ($maxIterations === 0) {
            return $prompt;
        }

        $new = clone $prompt;
        $new->iterationBudget = <<<BUDGET
            You have **{$maxIterations} iterations** to complete this task. Each iteration is one round-trip with the provider — you send a message, receive a response, and optionally execute tool calls. When all iterations are consumed, execution stops.

            **Manage your budget wisely:**
            - Batch multiple independent tool calls in a single iteration when possible.
            - Prioritize the most impactful actions early.
            - If you are running low on iterations, summarize your progress and prepare questions or next steps for the user so work can continue in the next turn.
            BUDGET;

        return $new;
    }

    /**
     * Add toolkit guidelines.
     *
     * @param ToolkitInterface[] $toolkits
     */
    public static function withToolkits(array $toolkits, self $prompt): self
    {
        $new = clone $prompt;
        $guidelines = [];

        foreach ($toolkits as $toolkit) {
            $guidelines[] = $toolkit->guidelines();
        }

        $new->guidelines = implode("\n\n", $guidelines);

        return $new;
    }

    /**
     * Render to final string.
     */
    public static function render(self $prompt): string
    {
        $sections = [];

        if ($prompt->identity !== '') {
            $sections[] = "# IDENTITY AND PURPOSE\n\n{$prompt->identity}";
        }

        if ($prompt->instructions !== '') {
            $sections[] = "# INSTRUCTIONS\n\n{$prompt->instructions}";
        }

        if ($prompt->tools !== '') {
            $sections[] = "# TOOLS\n\n{$prompt->tools}";
        }

        if ($prompt->iterationBudget !== '') {
            $sections[] = "# ITERATION BUDGET\n\n{$prompt->iterationBudget}";
        }

        if ($prompt->guidelines !== '') {
            $sections[] = "# TOOL USAGE RULES\n\n{$prompt->guidelines}";
        }

        return implode("\n\n---\n\n", $sections);
    }
}
