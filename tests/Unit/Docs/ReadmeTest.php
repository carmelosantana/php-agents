<?php

declare(strict_types=1);

// The README linked docs/architecture.md and friends while the files are upper-case, and its
// quick start called a method that did not exist. Links must resolve and the quick start must
// only use classes and methods that exist.

$root = dirname(__DIR__, 3);

test('every relative README link resolves', function () use ($root) {
    $readme = (string) file_get_contents($root . '/README.md');
    preg_match_all('/\]\((?!https?:|#|mailto:)([^)#\s]+)/', $readme, $m);

    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $path) {
        expect(file_exists($root . '/' . $path))->toBeTrue("README links a missing file: {$path}");
    }
});

test('the quick start comes first and stays short', function () use ($root) {
    $readme = (string) file_get_contents($root . '/README.md');
    preg_match('/## Quick start\s+```php\n(.*?)```/s', $readme, $m);

    expect($m)->not->toBeEmpty()
        ->and(strpos($readme, '## Quick start'))->toBeLessThan(strpos($readme, '## Install') ?: PHP_INT_MAX)
        ->and(substr_count(trim($m[1]), "\n") + 1)->toBeLessThanOrEqual(12);
});

test('the quick start uses only classes that exist', function () use ($root) {
    $readme = (string) file_get_contents($root . '/README.md');
    preg_match('/## Quick start\s+```php\n(.*?)```/s', $readme, $m);
    preg_match_all('/^use ([A-Za-z\\\\]+);/m', $m[1] ?? '', $uses);

    expect($uses[1])->not->toBeEmpty();
    foreach ($uses[1] as $class) {
        expect(class_exists($class))->toBeTrue("{$class} does not exist");
    }
});
