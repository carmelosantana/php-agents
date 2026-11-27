<?php

declare(strict_types=1);

namespace Tests\Support\Docs;

use RuntimeException;

final readonly class SnippetResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * The snippet's stdout, or an exception carrying its exit code and stderr when it failed.
     */
    public function output(): string
    {
        if ($this->exitCode !== 0) {
            throw new RuntimeException(sprintf(
                "Snippet exited with code %d.\nstderr:\n%s\nstdout:\n%s",
                $this->exitCode,
                $this->stderr,
                $this->stdout,
            ));
        }

        return $this->stdout;
    }
}
