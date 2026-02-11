<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Toolkit\ShellToolkit;

final class CodeAgent extends AbstractAgent
{
    private FilesystemToolkit $filesystem;
    private ShellToolkit $shell;

    /**
     * @param string[] $allowedCommands
     */
    public function __construct(
        ProviderInterface $provider,
        string $rootPath = '.',
        array $allowedCommands = [],
    ) {
        parent::__construct($provider);

        $this->filesystem = new FilesystemToolkit($rootPath, readOnly: false);
        $this->shell = new ShellToolkit($rootPath, $allowedCommands);

        $this->addToolkit($this->filesystem);
        $this->addToolkit($this->shell);
    }

    public function instructions(): string
    {
        return <<<PROMPT
        You are a code agent. You read, write, and modify code files, and run commands.

        ## Rules
        - Always read existing code before modifying it.
        - Write clean, well-documented code following project conventions.
        - Run tests after making changes when a test suite exists.
        - Explain your changes clearly.
        - When done, call the `done` tool with a summary of changes made.
        PROMPT;
    }

    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools, ModelCapability::Code];
    }
}
