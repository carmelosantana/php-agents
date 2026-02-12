<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class ShellToolkit implements ToolkitInterface
{
    /**
     * Regex patterns that indicate dangerous shell constructs.
     * These are checked in addition to the configurable denylist.
     *
     * @var string[]
     */
    private const DENIED_PATTERNS = [
        '/\brm\s+(-[a-z]*r[a-z]*\s+-[a-z]*f|-[a-z]*f[a-z]*\s+-[a-z]*r)\b/i',  // rm -rf / rm -fr variants
        '/\brm\s+-[a-z]*rf\b/i',                                                  // rm -rf combined
        '/\|\s*(bash|sh|zsh|dash)\b/i',                                            // Pipe to shell
        '/\bcurl\b.*\|\s*(bash|sh|zsh)\b/i',                                      // curl | bash
        '/\bwget\b.*\|\s*(bash|sh|zsh)\b/i',                                      // wget | bash
        '/\bphp\s+-r\b/i',                                                         // php -r inline execution
        '/\bmkfifo\b/i',                                                            // Named pipe creation
        '/\b(nc|ncat|netcat)\s/i',                                                  // Network tools
    ];

    /**
     * @param string[] $allowedCommands
     * @param string[] $deniedCommands
     */
    public function __construct(
        private readonly string $workDir = '.',
        private readonly array $allowedCommands = [],
        private readonly array $deniedCommands = ['sudo', 'chmod 777'],
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

                // Check configurable denylist (substring match)
                foreach ($this->deniedCommands as $denied) {
                    if (str_contains($command, $denied)) {
                        return ToolResult::error("Denied command pattern detected: {$denied}");
                    }
                }

                // Check built-in regex deny patterns
                foreach (self::DENIED_PATTERNS as $pattern) {
                    if (preg_match($pattern, $command)) {
                        return ToolResult::error("Denied: command matches a blocked security pattern.");
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
                        ? ToolResultStatus::Success
                        : ToolResultStatus::Error,
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
