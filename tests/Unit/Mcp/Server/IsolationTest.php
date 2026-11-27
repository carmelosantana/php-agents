<?php

declare(strict_types=1);

// Mutation: add `use Psr\Log\LoggerInterface;` to ToolError.php.
it('keeps server imports and fully qualified names inside the server or built-in allowlist', function (): void {
    $root = dirname(__DIR__, 4) . '/src/Mcp/Server';
    expect(is_dir($root))->toBeTrue('The MCP server source directory is missing.');

    $serverPrefix = 'carmelosantana\\phpagents\\mcp\\server\\';
    $builtIns = ['jsonexception', 'invalidargumentexception', 'runtimeexception', 'stdclass', 'closure'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $violations = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $depth = 0;
        $namespaceDepth = 0;
        $inImport = false;
        $inGroup = false;

        foreach ($tokens as $index => $token) {
            if ($depth === 0 && $token->is(T_NAMESPACE)) {
                $separator = $tokens[$index + 1]->is('{') ? $index + 1 : $index + 2;
                $namespaceDepth = $tokens[$separator]->is('{') ? 1 : 0;
            }

            if ($token->is(T_USE) && $depth === $namespaceDepth && ! $tokens[$index + 1]->is('(')) {
                $inImport = true;
            } elseif ($inImport && $token->is(';')) {
                $inImport = false;
                $inGroup = false;
            } elseif ($inImport && $token->is('{')) {
                $inGroup = true;
            } elseif (! $inImport) {
                if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    ++$depth;
                } elseif ($token->is('}')) {
                    --$depth;
                }
            }

            $isImportName = $inImport && ! $inGroup
                && $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && ! $tokens[$index - 1]->is(T_AS);

            if (! $isImportName && ! ($token->is(T_NAME_FULLY_QUALIFIED) && ! $inImport)) {
                continue;
            }

            $name = strtolower(ltrim($token->text, '\\'));
            if ($isImportName && $tokens[$index + 1]->is(T_NS_SEPARATOR)) {
                $allowed = str_starts_with($name . '\\', $serverPrefix);
            } else {
                $allowed = str_starts_with($name, $serverPrefix) || in_array($name, $builtIns, true);
            }

            if (! $allowed) {
                $violations[] = substr($file->getPathname(), strlen($root) + 1)
                    . ':' . $token->line . ' references ' . $token->text;
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});
