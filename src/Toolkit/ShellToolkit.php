<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class ShellToolkit implements ToolkitInterface
{
    /**
     * @param string[] $allowedCommands
     * @param string[] $deniedCommands
     */
    public function __construct(
        private readonly string $workDir = '.',
        private readonly array $allowedCommands = [],
        private readonly array $deniedCommands = ['rm -rf /', 'sudo', 'chmod 777'],
        private readonly int $timeout = 30,
    ) {}

    public function tools(): array
    {
        return [$this->execTool()];
    }

    public function guidelines(): string
    {
        $allowed = empty($this->allowedCommands) ? 'all (except denied)' : implode(', ', $this->allowedCommands);

        return <<<GUIDELINES
        <SHELL-GUIDELINES>
        Working directory: {$this->workDir}
        Allowed commands: {$allowed}
        Timeout: {$this->timeout}s
        - Use shell commands for build, test, and system operations.
        - Prefer specific commands over broad ones.
        - Always check exit codes and stderr.
        </SHELL-GUIDELINES>
        GUIDELINES;
    }

    private function execTool(): ToolInterface
    {
        return new Tool(
            name: 'exec',
            description: 'Execute a shell command.',
            parameters: [
                new StringParameter('command', 'The shell command to execute'),
                new NumberParameter('timeout', 'Timeout in seconds', required: false, integer: true),
            ],
            callback: function (array $input): ToolResult {
                $command = $input['command'] ?? '';
                $timeout = (int) ($input['timeout'] ?? $this->timeout);

                if ($command === '') {
                    return ToolResult::error('Command is required');
                }

                if (!$this->isCommandAllowed($command)) {
                    return ToolResult::error("Command not allowed: {$command}");
                }

                foreach ($this->deniedCommands as $denied) {
                    if (str_contains($command, $denied)) {
                        return ToolResult::error("Denied command pattern detected: {$denied}");
                    }
                }

                $descriptorSpec = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];

                $process = proc_open(
                    $command,
                    $descriptorSpec,
                    $pipes,
                    $this->workDir,
                );

                if (!is_resource($process)) {
                    return ToolResult::error("Failed to execute command: {$command}");
                }

                fclose($pipes[0]);

                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $stdout = '';
                $stderr = '';
                $startTime = time();

                while (proc_get_status($process)['running']) {
                    $stdout .= stream_get_contents($pipes[1]) ?: '';
                    $stderr .= stream_get_contents($pipes[2]) ?: '';

                    if (time() - $startTime > $timeout) {
                        proc_terminate($process);
                        fclose($pipes[1]);
                        fclose($pipes[2]);
                        proc_close($process);

                        return ToolResult::error("Command timed out after {$timeout}s");
                    }

                    usleep(10000);
                }

                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';

                fclose($pipes[1]);
                fclose($pipes[2]);

                $exitCode = proc_close($process);

                $result = [
                    'exit_code' => $exitCode,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ];

                return new ToolResult(
                    status: $exitCode === 0
                        ? \CarmeloSantana\PHPAgents\Enum\ToolResultStatus::Success
                        : \CarmeloSantana\PHPAgents\Enum\ToolResultStatus::Error,
                    content: json_encode($result, JSON_PRETTY_PRINT) ?: '',
                );
            },
        );
    }

    private function isCommandAllowed(string $command): bool
    {
        if (empty($this->allowedCommands)) {
            return true;
        }

        $firstWord = explode(' ', trim($command))[0];

        foreach ($this->allowedCommands as $allowed) {
            if ($firstWord === $allowed || str_starts_with($command, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
