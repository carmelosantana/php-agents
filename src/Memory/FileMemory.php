<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Memory;

use CarmeloSantana\PHPAgents\Contract\MemoryInterface;
use DateTimeImmutable;

final class FileMemory implements MemoryInterface
{
    private string $content;

    public function __construct(
        private readonly string $filePath = 'MEMORY.md',
    ) {
        $this->content = file_exists($filePath) ? (file_get_contents($filePath) ?: '') : '';
    }

    public function save(MemoryEntry $entry): string
    {
        $id = bin2hex(random_bytes(8));
        $timestamp = date('Y-m-d H:i');

        $this->content .= "\n\n### [{$entry->area}] {$timestamp} (id: {$id})\n\n{$entry->content}";

        $this->persist();

        return $id;
    }

    public function search(string $query, int $limit = 10, float $threshold = 0.7): array
    {
        $entries = $this->parseEntries();
        $results = [];

        foreach ($entries as $entry) {
            if (stripos($entry->content, $query) !== false) {
                $results[] = $entry;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    public function delete(string $id): void
    {
        // Match the exact entry block from its header to the next header or end of content.
        // The negative lookahead ensures we don't consume into the next entry.
        $pattern = '/\n\n### \[[^\]]+\] [^\(]+ \(id: ' . preg_quote($id, '/') . '\)\n\n(?:(?!\n### \[).)*+/s';
        $this->content = preg_replace($pattern, '', $this->content) ?? $this->content;
        $this->persist();
    }

    public function forget(string $query, float $threshold = 0.7): int
    {
        $matches = $this->search($query, limit: 100, threshold: $threshold);

        foreach ($matches as $entry) {
            if ($entry->id !== null) {
                $this->delete($entry->id);
            }
        }

        return count($matches);
    }

    public function list(string $area = 'main', int $limit = 50): array
    {
        $entries = $this->parseEntries();

        return array_slice(
            array_filter($entries, fn(MemoryEntry $e) => $e->area === $area),
            0,
            $limit,
        );
    }

    public function getFullContent(): string
    {
        return $this->content;
    }

    /**
     * @return MemoryEntry[]
     */
    private function parseEntries(): array
    {
        $entries = [];
        $pattern = '/### \[([^\]]+)\] ([^\(]+) \(id: ([a-f0-9]+)\)\n\n([^#]*)/';

        if (preg_match_all($pattern, $this->content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entries[] = new MemoryEntry(
                    content: trim($match[4]),
                    area: $match[1],
                    metadata: [],
                    id: $match[3],
                    createdAt: new DateTimeImmutable($match[2]),
                );
            }
        }

        return $entries;
    }

    private function persist(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Use exclusive locking to prevent concurrent write corruption
        $handle = fopen($this->filePath, 'c');
        if ($handle === false) {
            return;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                fwrite($handle, $this->content);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
