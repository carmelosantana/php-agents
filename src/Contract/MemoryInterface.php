<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Memory\MemoryEntry;

/**
 * Contract for persistent agent memory storage.
 */
interface MemoryInterface
{
    /**
     * Save a memory entry.
     *
     * @return string The ID of the saved entry
     */
    public function save(MemoryEntry $entry): string;

    /**
     * Search memories by semantic query.
     *
     * @return MemoryEntry[]
     */
    public function search(string $query, int $limit = 10, float $threshold = 0.7): array;

    /**
     * Delete a specific memory by ID.
     */
    public function delete(string $id): void;

    /**
     * Delete all memories semantically matching a query.
     *
     * @return int Number of deleted entries
     */
    public function forget(string $query, float $threshold = 0.7): int;

    /**
     * List all memories in an area.
     *
     * @return MemoryEntry[]
     */
    public function list(string $area = 'main', int $limit = 50): array;
}
