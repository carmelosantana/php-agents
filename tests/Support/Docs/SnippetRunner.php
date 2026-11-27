<?php

declare(strict_types=1);

namespace Tests\Support\Docs;

use RuntimeException;

/**
 * Runs a PHP source file in a separate PHP process with the Composer autoloader loaded, so a
 * fatal error in a doc snippet fails one test instead of ending the test run.
 */
final class SnippetRunner
{
    /**
     * @param string $code A complete PHP file, opening tag included.
     */
    public static function run(string $code): SnippetResult
    {
        $file = tempnam(sys_get_temp_dir(), 'php-agents-snippet-');
        if ($file === false) {
            throw new RuntimeException('Could not create a temp file for the snippet.');
        }

        try {
            file_put_contents($file, $code);

            // The autoloader loads through auto_prepend_file, so a snippet may open with a
            // namespace declaration.
            $command = [
                PHP_BINARY,
                '-d', 'auto_prepend_file=' . dirname(__DIR__, 3) . '/vendor/autoload.php',
                '-d', 'display_errors=stderr',
                '-d', 'error_reporting=-1',
                $file,
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if ($process === false) {
                throw new RuntimeException('Could not start PHP for the snippet.');
            }

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return new SnippetResult(proc_close($process), $stdout, $stderr);
        } finally {
            unlink($file);
        }
    }
}
