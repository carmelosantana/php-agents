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
        $prompt->instructions = $instructions;

        return $prompt;
    }

    /**
     * Inject tool documentation.
     *
     * @param ToolInterface[] $tools
     */
    public static function withTools(array $tools, self $prompt): self
    {
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

        $prompt->tools = implode("\n", $lines);

        return $prompt;
    }

    /**
     * Add toolkit guidelines.
     *
     * @param ToolkitInterface[] $toolkits
     */
    public static function withToolkits(array $toolkits, self $prompt): self
    {
        $guidelines = [];

        foreach ($toolkits as $toolkit) {
            $guidelines[] = $toolkit->guidelines();
        }

        $prompt->guidelines = implode("\n\n", $guidelines);

        return $prompt;
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

        if ($prompt->guidelines !== '') {
            $sections[] = "# TOOL USAGE RULES\n\n{$prompt->guidelines}";
        }

        return implode("\n\n---\n\n", $sections);
    }
}
