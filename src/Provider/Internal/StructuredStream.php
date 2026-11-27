<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Internal;

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\StructuredDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd;
use CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart;

/**
 * Turns a provider's streamEvents() into the events of StructuredStreaming::structuredEvents().
 * Not public API.
 *
 * ReasoningDelta, Tick and the Finish pass through; the stream ends at the Finish. Each method
 * reads the document from a different place and drops every other event.
 */
final class StructuredStream
{
    /**
     * The document is the reply text: each TextDelta becomes a StructuredDelta.
     *
     * @param iterable<StreamEvent> $events
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    public static function fromText(iterable $events): \Generator
    {
        foreach ($events as $event) {
            if ($event instanceof TextDelta) {
                yield new StructuredDelta($event->delta);
            } elseif ($event instanceof ReasoningDelta || $event instanceof Tick) {
                yield $event;
            } elseif ($event instanceof Finish) {
                yield $event;

                return;
            }
        }
    }

    /**
     * The document is the arguments of the first call to the function named $name, which is
     * never executed. Its argument fragments become StructuredDelta. A ToolUse finish becomes
     * Stop once that call has ended, and Error when it has not.
     *
     * @param iterable<StreamEvent> $events
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    public static function fromFunction(iterable $events, string $name): \Generator
    {
        $index = null;
        $ended = false;

        foreach ($events as $event) {
            if ($event instanceof ToolInputStart) {
                if ($index === null && $event->name === $name) {
                    $index = $event->index;
                }
            } elseif ($event instanceof ToolInputDelta) {
                if ($event->index === $index && !$ended) {
                    yield new StructuredDelta($event->delta);
                }
            } elseif ($event instanceof ToolInputEnd) {
                $ended = $ended || $event->index === $index;
            } elseif ($event instanceof ReasoningDelta || $event instanceof Tick) {
                yield $event;
            } elseif ($event instanceof Finish) {
                yield $event->reason === ProviderFinishReason::ToolUse
                    ? new Finish(
                        $ended ? ProviderFinishReason::Stop : ProviderFinishReason::Error,
                        $event->rawReason,
                        $event->usage,
                        $event->model,
                        $event->contentBlocks,
                    )
                    : $event;

                return;
            }
        }
    }
}
