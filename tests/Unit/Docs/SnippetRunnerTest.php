<?php

declare(strict_types=1);

use Tests\Support\Docs\SnippetRunner;

test('SnippetRunner runs a snippet with the autoloader and returns its output', function () {
    $result = SnippetRunner::run(<<<'PHP'
        <?php
        echo \CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop->value;
        fwrite(STDERR, 'note');
        PHP);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toBe('stop')
        ->and($result->stderr)->toBe('note')
        ->and($result->output())->toBe('stop');
});

test('SnippetRunner reports a syntax error in the snippet instead of ending the run', function () {
    $result = SnippetRunner::run("<?php\necho 'unclosed;\n");

    expect($result->exitCode)->toBe(255)
        ->and($result->stderr)->toContain('syntax error')
        ->and(fn () => $result->output())->toThrow(RuntimeException::class, 'syntax error');
});

test('SnippetRunner passes the snippet to PHP without a shell', function () {
    $result = SnippetRunner::run('<?php echo "$(echo hi) `id` \'quoted\'";');

    expect($result->output())->toBe("$(echo hi) `id` 'quoted'");
});

test('SnippetRunner removes its temp file', function () {
    $before = glob(sys_get_temp_dir() . '/php-agents-snippet-*') ?: [];
    SnippetRunner::run('<?php echo __FILE__;');

    expect(glob(sys_get_temp_dir() . '/php-agents-snippet-*') ?: [])->toBe($before);
});
