<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Prompt;

use CarmeloSantana\PHPAgents\Contract\ToolDocumentationInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\Parameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

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

            if ($tool instanceof ToolDocumentationInterface) {
                $useWhen = $tool->useWhen();
                if ($useWhen !== null && trim($useWhen) !== '') {
                    $lines[] = "Use when: {$useWhen}";
                }

                $examples = $tool->examples();
                if ($examples !== []) {
                    $lines[] = 'Examples:';
                    foreach ($examples as $example) {
                        $lines[] = "  - {$example}";
                    }
                }
            }

            $params = $tool->parameters();
            if (!empty($params)) {
                $lines[] = "Parameters:";
                foreach ($params as $param) {
                    $req = $param->required ? '(required)' : '(optional)';
                    $constraintText = self::describeParameterConstraints($param);
                    $lines[] = "  - `{$param->name}` {$req}: {$param->description}{$constraintText}";
                }
            }
            $lines[] = '';
        }

        $new->tools = implode("\n", $lines);

        return $new;
    }

    private static function describeParameterConstraints(Parameter $param): string
    {
        $parts = [];

        if ($param instanceof StringParameter) {
            if ($param->enum !== null && $param->enum !== []) {
                $parts[] = 'accepted values: ' . implode(', ', $param->enum);
            }

            if ($param->maxLength !== null) {
                $parts[] = sprintf('max length: %d', $param->maxLength);
            }

            if ($param->pattern !== null) {
                $parts[] = 'pattern: ' . $param->pattern;
            }
        }

        if ($param instanceof EnumParameter) {
            $parts[] = 'accepted values: ' . implode(', ', $param->values);
        }

        if ($param instanceof NumberParameter) {
            if ($param->integer) {
                $parts[] = 'integer';
            }

            if ($param->minimum !== null) {
                $parts[] = 'min: ' . $param->minimum;
            }

            if ($param->maximum !== null) {
                $parts[] = 'max: ' . $param->maximum;
            }
        }

        if ($param instanceof ArrayParameter && $param->items !== null) {
            $parts[] = 'item type: ' . self::describeParameterType($param->items);
        }

        if ($param instanceof ObjectParameter && $param->properties !== []) {
            $parts[] = 'properties: ' . implode(', ', array_map(
                static fn(Parameter $property): string => sprintf('%s%s', $property->name, $property->required ? '' : '?'),
                $param->properties,
            ));
        }

        return $parts === [] ? '' : ' [' . implode('; ', $parts) . ']';
    }

    private static function describeParameterType(Parameter $param): string
    {
        return match (true) {
            $param instanceof StringParameter => 'string',
            $param instanceof EnumParameter => 'enum',
            $param instanceof NumberParameter => $param->integer ? 'integer' : 'number',
            $param instanceof ArrayParameter => 'array',
            $param instanceof ObjectParameter => 'object',
            default => 'value',
        };
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
            $sections[] = "# TOOLS\n\n{$prompt->tools}\n"
                . "**When multiple tools can be called independently, call them all in the same response rather than one per iteration.**";
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
