<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class FilesystemToolkit implements ToolkitInterface
{
    /**
     * @param string $rootPath       Primary sandbox root directory.
     * @param bool   $readOnly       When true, write tools are not registered.
     * @param array<int, array{realPath: string, readOnly: bool}> $allowedPaths
     *        Additional allowed directories (e.g. mounts). Each entry has a
     *        realPath (absolute canonical path) and a readOnly flag. Paths
     *        that resolve under an allowed entry pass the sandbox check even
     *        if they are outside the root (e.g. via symlink).
     */
    public function __construct(
        private readonly string $rootPath = '.',
        private readonly bool $readOnly = false,
        private readonly array $allowedPaths = [],
    ) {}

    public function tools(): array
    {
        $tools = [
            $this->readFileTool(),
            $this->listDirTool(),
            $this->searchFilesTool(),
            $this->fileInfoTool(),
        ];

        if (!$this->readOnly) {
            $tools[] = $this->writeFileTool();
            $tools[] = $this->createDirTool();
            $tools[] = $this->deleteFileTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $mode = $this->readOnly ? 'READ-ONLY' : 'READ/WRITE';

        $mountInfo = '';
        if ($this->allowedPaths !== []) {
            $mountInfo = "\n        - Additional mounted directories are available under mnt/ in the workspace.";
            $mountInfo .= "\n        - Write to mounted directories only when explicitly instructed.";
        }

        return <<<GUIDELINES
        <FILESYSTEM-GUIDELINES>
        Mode: {$mode}
        Root: {$this->rootPath}
        - All paths are relative to the root directory.
        - Use list_dir before read_file to understand directory structure.
        - Read files before modifying them.
        - Use search_files with glob patterns to find specific files.{$mountInfo}
        </FILESYSTEM-GUIDELINES>
        GUIDELINES;
    }

    private function readFileTool(): ToolInterface
    {
        return new Tool(
            name: 'read_file',
            description: 'Read the contents of a file.',
            parameters: [
                new StringParameter('path', 'Path to the file relative to root'),
            ],
            callback: function (array $input): ToolResult {
                $path = $this->resolvePath($input['path'] ?? '');

                if (!file_exists($path)) {
                    return ToolResult::error("File not found: {$input['path']}");
                }

                if (!is_file($path)) {
                    return ToolResult::error("Not a file: {$input['path']}");
                }

                $content = file_get_contents($path);
                if ($content === false) {
                    return ToolResult::error("Failed to read file: {$input['path']}");
                }

                return ToolResult::success($content);
            },
        );
    }

    private function writeFileTool(): ToolInterface
    {
        return new Tool(
            name: 'write_file',
            description: 'Write content to a file. Creates the file if it does not exist.',
            parameters: [
                new StringParameter('path', 'Path to the file relative to root'),
                new StringParameter('content', 'Content to write to the file'),
            ],
            callback: function (array $input): ToolResult {
                $path = $this->resolvePath($input['path'] ?? '');
                $content = $input['content'] ?? '';

                // Check if writing to a read-only mount
                if ($this->isReadOnlyMountPath($path)) {
                    return ToolResult::error("Cannot write — mount is read-only: {$input['path']}");
                }

                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                if (file_put_contents($path, $content) === false) {
                    return ToolResult::error("Failed to write file: {$input['path']}");
                }

                return ToolResult::success("File written: {$input['path']}");
            },
        );
    }

    private function listDirTool(): ToolInterface
    {
        return new Tool(
            name: 'list_dir',
            description: 'List files and directories in a directory.',
            parameters: [
                new StringParameter('path', 'Path to the directory relative to root', required: false),
                new BoolParameter('recursive', 'List recursively', required: false),
            ],
            callback: function (array $input): ToolResult {
                $path = $this->resolvePath($input['path'] ?? '.');
                $recursive = $input['recursive'] ?? false;

                if (!is_dir($path)) {
                    return ToolResult::error("Directory not found: {$input['path']}");
                }

                $entries = [];
                if ($recursive) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    );
                    foreach ($iterator as $file) {
                        $relativePath = str_replace($this->rootPath . '/', '', $file->getPathname());
                        $type = $file->isDir() ? 'd' : 'f';
                        $entries[] = "[{$type}] {$relativePath}";
                    }
                } else {
                    $items = scandir($path);
                    if ($items === false) {
                        return ToolResult::error("Failed to list directory: {$input['path']}");
                    }
                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }
                        $fullPath = "{$path}/{$item}";
                        $type = is_dir($fullPath) ? 'd' : 'f';
                        $entries[] = "[{$type}] {$item}";
                    }
                }

                return ToolResult::success(implode("\n", $entries));
            },
        );
    }

    private function searchFilesTool(): ToolInterface
    {
        return new Tool(
            name: 'search_files',
            description: 'Search for files matching a pattern.',
            parameters: [
                new StringParameter('pattern', 'Glob pattern to match (e.g., "*.php", "**/*.json")'),
            ],
            callback: function (array $input): ToolResult {
                $pattern = $input['pattern'] ?? '*';
                $fullPattern = "{$this->rootPath}/{$pattern}";

                $files = glob($fullPattern, GLOB_BRACE) ?: [];

                if (empty($files)) {
                    return ToolResult::success("No files found matching: {$pattern}");
                }

                $relativePaths = array_map(
                    fn($f) => str_replace($this->rootPath . '/', '', $f),
                    $files,
                );

                return ToolResult::success(implode("\n", $relativePaths));
            },
        );
    }

    private function fileInfoTool(): ToolInterface
    {
        return new Tool(
            name: 'file_info',
            description: 'Get information about a file (size, modified time, etc.).',
            parameters: [
                new StringParameter('path', 'Path to the file relative to root'),
            ],
            callback: function (array $input): ToolResult {
                $path = $this->resolvePath($input['path'] ?? '');

                if (!file_exists($path)) {
                    return ToolResult::error("File not found: {$input['path']}");
                }

                $stat = stat($path);
                if ($stat === false) {
                    return ToolResult::error("Failed to get file info: {$input['path']}");
                }

                $info = [
                    'path' => $input['path'],
                    'type' => is_dir($path) ? 'directory' : 'file',
                    'size' => $stat['size'],
                    'modified' => date('Y-m-d H:i:s', $stat['mtime']),
                    'readable' => is_readable($path),
                    'writable' => is_writable($path),
                ];

                return ToolResult::success(json_encode($info, JSON_PRETTY_PRINT) ?: '');
            },
        );
    }

    private function createDirTool(): ToolInterface
    {
        return new Tool(
            name: 'create_dir',
            description: 'Create a directory.',
            parameters: [
                new StringParameter('path', 'Path to the directory relative to root'),
            ],
            callback: function (array $input): ToolResult {
                $path = $this->resolvePath($input['path'] ?? '');

                // Check if creating in a read-only mount
                if ($this->isReadOnlyMountPath($path)) {
                    return ToolResult::error("Cannot create directory — mount is read-only: {$input['path']}");
                }

                if (is_dir($path)) {
                    return ToolResult::success("Directory already exists: {$input['path']}");
                }

                if (!mkdir($path, 0755, true)) {
                    return ToolResult::error("Failed to create directory: {$input['path']}");
                }

                return ToolResult::success("Directory created: {$input['path']}");
            },
        );
    }

    private function deleteFileTool(): ToolInterface
    {
        return new Tool(
            name: 'delete_file',
            description: 'Delete a file.',
            parameters: [
                new StringParameter('path', 'Path to the file relative to root'),
            ],
            callback: function (array $input): ToolResult {
                $path = $this->resolvePath($input['path'] ?? '');

                // Check if deleting from a read-only mount
                if ($this->isReadOnlyMountPath($path)) {
                    return ToolResult::error("Cannot delete — mount is read-only: {$input['path']}");
                }

                if (!file_exists($path)) {
                    return ToolResult::error("File not found: {$input['path']}");
                }

                if (is_dir($path)) {
                    return ToolResult::error("Cannot delete directory with this tool: {$input['path']}");
                }

                if (!unlink($path)) {
                    return ToolResult::error("Failed to delete file: {$input['path']}");
                }

                return ToolResult::success("File deleted: {$input['path']}");
            },
        );
    }

    private function resolvePath(string $relativePath): string
    {
        // Canonicalize relative path segments manually to prevent traversal
        // when realpath() fails (file doesn't exist yet).
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $segment;
            }
        }
        $canonicalized = implode('/', $resolved);

        $path = "{$this->rootPath}/{$canonicalized}";
        $realRoot = realpath($this->rootPath);

        if ($realRoot === false) {
            return $path;
        }

        $realPath = realpath($path);

        if ($realPath !== false) {
            // Existing path — verify it's within the root or an allowed path
            if (str_starts_with($realPath, $realRoot)) {
                return $realPath;
            }

            // Check against allowed paths (mounts)
            if ($this->isUnderAllowedPath($realPath)) {
                return $realPath;
            }

            return $this->rootPath;
        }

        // Path doesn't exist yet (new file). Verify the canonicalized version
        // still resides within the root by checking the deepest existing
        // ancestor directory.
        $parentPath = dirname($path);
        $realParent = realpath($parentPath);
        if ($realParent !== false) {
            if (!str_starts_with($realParent, $realRoot) && !$this->isUnderAllowedPath($realParent)) {
                return $this->rootPath;
            }
        }

        return $path;
    }

    /**
     * Check if an absolute real path falls under any allowed (mounted) path.
     */
    private function isUnderAllowedPath(string $realPath): bool
    {
        foreach ($this->allowedPaths as $allowed) {
            if (str_starts_with($realPath, $allowed['realPath'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an absolute real path falls under a read-only mount.
     *
     * Returns false if the path is under the primary root (not a mount),
     * or if the mount is read-write.
     */
    private function isReadOnlyMountPath(string $absolutePath): bool
    {
        $realRoot = realpath($this->rootPath);
        $realPath = realpath($absolutePath);

        // For non-existent files (new writes), resolve via parent directory
        if ($realPath === false) {
            $realPath = realpath(dirname($absolutePath));
        }

        // If realpath still fails or path is under the primary root, not a mount
        if ($realPath === false || $realRoot === false) {
            return false;
        }

        if (str_starts_with($realPath, $realRoot)) {
            return false;
        }

        // Path is outside root — check if it's under a read-only mount
        foreach ($this->allowedPaths as $allowed) {
            if (str_starts_with($realPath, $allowed['realPath'])) {
                return $allowed['readOnly'];
            }
        }

        return false;
    }
}
