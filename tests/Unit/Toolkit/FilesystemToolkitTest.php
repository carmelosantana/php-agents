<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

beforeEach(function () {
    // Primary workspace root
    $this->root = sys_get_temp_dir() . '/php-agents-fs-test-' . bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
    file_put_contents($this->root . '/hello.txt', 'hello world');

    // External mount directory
    $this->mountDir = sys_get_temp_dir() . '/php-agents-mount-test-' . bin2hex(random_bytes(4));
    mkdir($this->mountDir, 0755, true);
    file_put_contents($this->mountDir . '/data.txt', 'mount data');

    // Create symlink inside root pointing to external mount
    symlink($this->mountDir, $this->root . '/mnt');
});

afterEach(function () {
    // Clean up — only remove files that exist
    $filesToClean = [
        $this->root . '/mnt',
        $this->root . '/hello.txt',
        $this->root . '/new-file.txt',
        $this->mountDir . '/data.txt',
        $this->mountDir . '/written.txt',
        $this->mountDir . '/blocked.txt',
    ];
    foreach ($filesToClean as $f) {
        if (is_link($f) || is_file($f)) {
            unlink($f);
        }
    }

    $dirsToClean = [
        $this->mountDir . '/subdir',
        $this->root,
        $this->mountDir,
    ];
    foreach ($dirsToClean as $dir) {
        if (is_dir($dir)) {
            // Remove any remaining files first
            $remaining = glob($dir . '/*') ?: [];
            foreach ($remaining as $f) {
                if (is_link($f) || is_file($f)) {
                    unlink($f);
                }
            }
            rmdir($dir);
        }
    }
});

// --- Construction Tests ---

test('constructs without allowedPaths (backward compatible)', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);

    expect($toolkit->tools())->not->toBeEmpty();
});

test('constructs with empty allowedPaths', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [],
    );

    expect($toolkit->tools())->not->toBeEmpty();
});

test('constructs with allowedPaths parameter', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [
            ['realPath' => realpath($this->mountDir), 'readOnly' => false],
        ],
    );

    expect($toolkit->tools())->not->toBeEmpty();
});

// --- Tool Registration ---

test('read-write mode registers all tools', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toContain('read_file');
    expect($names)->toContain('list_dir');
    expect($names)->toContain('search_files');
    expect($names)->toContain('file_info');
    expect($names)->toContain('write_file');
    expect($names)->toContain('create_dir');
    expect($names)->toContain('delete_file');
});

test('read-only mode excludes write tools', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root, readOnly: true);
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toContain('read_file');
    expect($names)->toContain('list_dir');
    expect($names)->not->toContain('write_file');
    expect($names)->not->toContain('delete_file');
    expect($names)->not->toContain('create_dir');
});

// --- Read Operations ---

test('read_file works for files in root', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);
    $tool = findToolByName($toolkit, 'read_file');

    $result = $tool->execute(['path' => 'hello.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('hello world');
});

test('read_file via symlink works with allowedPaths', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [
            ['realPath' => realpath($this->mountDir), 'readOnly' => false],
        ],
    );
    $tool = findToolByName($toolkit, 'read_file');

    $result = $tool->execute(['path' => 'mnt/data.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('mount data');
});

test('read_file via symlink fails without allowedPaths', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);
    $tool = findToolByName($toolkit, 'read_file');

    $result = $tool->execute(['path' => 'mnt/data.txt']);

    // Symlink resolves outside root — without allowedPaths it falls back to rootPath
    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('read_file returns error for nonexistent file', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);
    $tool = findToolByName($toolkit, 'read_file');

    $result = $tool->execute(['path' => 'missing.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

// --- Write Operations with Mount Access Control ---

test('write_file blocks write to read-only mount', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [
            ['realPath' => realpath($this->mountDir), 'readOnly' => true],
        ],
    );
    $tool = findToolByName($toolkit, 'write_file');

    $result = $tool->execute(['path' => 'mnt/blocked.txt', 'content' => 'nope']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('read-only');
});

test('write_file allows write to read-write mount', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [
            ['realPath' => realpath($this->mountDir), 'readOnly' => false],
        ],
    );
    $tool = findToolByName($toolkit, 'write_file');

    $result = $tool->execute(['path' => 'mnt/written.txt', 'content' => 'mount write ok']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->mountDir . '/written.txt'))->toBe('mount write ok');
});

test('write_file works for files in root', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);
    $tool = findToolByName($toolkit, 'write_file');

    $result = $tool->execute(['path' => 'new-file.txt', 'content' => 'new content']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_get_contents($this->root . '/new-file.txt'))->toBe('new content');
});

// --- Delete Operations with Mount Access Control ---

test('delete_file blocks delete from read-only mount', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [
            ['realPath' => realpath($this->mountDir), 'readOnly' => true],
        ],
    );
    $tool = findToolByName($toolkit, 'delete_file');

    $result = $tool->execute(['path' => 'mnt/data.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('read-only');
    // File should still exist
    expect(file_exists($this->mountDir . '/data.txt'))->toBeTrue();
});

// --- Create Directory with Mount Access Control ---

test('create_dir blocks in read-only mount', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [
            ['realPath' => realpath($this->mountDir), 'readOnly' => true],
        ],
    );
    $tool = findToolByName($toolkit, 'create_dir');

    $result = $tool->execute(['path' => 'mnt/subdir']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('read-only');
});

// --- Guidelines ---

test('guidelines include mount info when allowedPaths set', function () {
    $toolkit = new FilesystemToolkit(
        rootPath: $this->root,
        allowedPaths: [
            ['realPath' => realpath($this->mountDir), 'readOnly' => false],
        ],
    );

    expect($toolkit->guidelines())->toContain('mounted directories');
});

test('guidelines exclude mount info when no allowedPaths', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);

    expect($toolkit->guidelines())->not->toContain('mounted directories');
});

// --- Path Traversal Protection ---

test('read_file rejects path traversal above root', function () {
    $toolkit = new FilesystemToolkit(rootPath: $this->root);
    $tool = findToolByName($toolkit, 'read_file');

    $result = $tool->execute(['path' => '../../etc/passwd']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

/**
 * Find a tool by name from a toolkit's tools.
 */
function findToolByName(FilesystemToolkit $toolkit, string $name): ToolInterface
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }
    throw new \RuntimeException("Tool '{$name}' not found in toolkit");
}
