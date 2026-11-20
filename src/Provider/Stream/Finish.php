<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Usage;

/**
 * The last event of every stream that does not throw, emitted exactly once.
 */
final readonly class Finish implements StreamEvent
{
    /**
     * @param list<array<string, mixed>> $contentBlocks Provider-native assistant content blocks, in order
     */
    public function __construct(
        public ProviderFinishReason $reason,
        public string $rawReason = '',
        public ?Usage $usage = null,
        public string $model = '',
        public array $contentBlocks = [],
    ) {}
}
