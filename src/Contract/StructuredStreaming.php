<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Contract;

use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;

/**
 * A provider that can stream the request structured() sends.
 */
interface StructuredStreaming
{
    /**
     * The request structured() would send, streamed. Yields ReasoningDelta, StructuredDelta
     * (fragments of the JSON document), Tick, and exactly one Finish, last. Finish(Stop) means
     * the document is complete. Nothing here validates the document against the schema.
     *
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     * @return iterable<StreamEvent>
     */
    public function structuredEvents(array $messages, string $schema, array $options = []): iterable;
}
