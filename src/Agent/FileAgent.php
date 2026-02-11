<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;

final class FileAgent extends AbstractAgent
{
    private FilesystemToolkit $filesystem;

    public function __construct(
        ProviderInterface $provider,
        string $rootPath = '.',
        bool $readOnly = false,
    ) {
        parent::__construct($provider);
        $this->filesystem = new FilesystemToolkit($rootPath, $readOnly);
        $this->addToolkit($this->filesystem);
    }

    public function instructions(): string
    {
        return <<<PROMPT
        You are a filesystem agent. You help users read, write, search, and manage files.

        ## Rules
        - Always use the provided filesystem tools — never guess file contents.
        - List directories before diving into files to understand structure.
        - When writing files, show the user what you plan to write first.
        - Confirm destructive operations (delete) by explaining what will happen.
        - When done, call the `done` tool with a summary of what you did.
        PROMPT;
    }

    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools];
    }
}
