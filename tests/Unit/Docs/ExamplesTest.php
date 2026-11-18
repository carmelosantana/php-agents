<?php

declare(strict_types=1);

// Consumers that vendor this package (e.g. Kanboard AiConnector) shipped examples/ inside their
// release zips, where file scanners read it. Examples stay in the repo but out of the dist archive,
// and each one opens with declare(strict_types=1) straight after the open tag.

$root = dirname(__DIR__, 3);

test('examples are export-ignored from the dist archive', function () use ($root) {
    $out = shell_exec('git -C ' . escapeshellarg($root) . ' check-attr export-ignore -- examples/');

    expect((string) $out)->toContain('export-ignore: set');
});

test('every example opens with declare(strict_types=1)', function () use ($root) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/examples', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $source = (string) preg_replace('/\A#![^\n]*\n/', '', $source);

        expect($source)->toStartWith("<?php\n\ndeclare(strict_types=1);", $file->getPathname());
    }
});
