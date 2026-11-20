<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider\Stream;

use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Response;

/**
 * Projects typed stream events onto the stream() Response shape.
 *
 * One Response per TextDelta or ReasoningDelta (finish reason Stop, no usage, no tool
 * calls), then exactly one terminal Response when the events end with Finish, built from
 * it: its reason, usage, model and content blocks, plus the ToolInputEnd calls when the
 * reason is ToolUse. Tick and tool-input events produce no Response of their own.
 *
 * Past RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE ToolInputEnd events the projection keeps no
 * more calls: it yields one terminal Response with finish reason Error and no tool calls, and
 * stops reading the events.
 */
final class StreamProjector
{
    /**
     * @param iterable<StreamEvent> $events
     * @return \Generator<int, Response>
     */
    public static function toResponses(iterable $events): \Generator
    {
        $toolCalls = [];

        foreach ($events as $event) {
            if ($event instanceof TextDelta) {
                yield new Response($event->delta, ProviderFinishReason::Stop);
            } elseif ($event instanceof ReasoningDelta) {
                yield new Response('', ProviderFinishReason::Stop, reasoning: $event->delta);
            } elseif ($event instanceof ToolInputEnd) {
                if (count($toolCalls) >= RequestLimits::MAX_TOOL_CALLS_PER_RESPONSE) {
                    yield new Response(content: '', finishReason: ProviderFinishReason::Error);

                    return;
                }
                $toolCalls[] = $event->toToolCall();
            } elseif ($event instanceof Finish) {
                yield new Response(
                    content: '',
                    finishReason: $event->reason,
                    toolCalls: $event->reason === ProviderFinishReason::ToolUse ? $toolCalls : [],
                    model: $event->model,
                    usage: $event->usage,
                    contentBlocks: $event->contentBlocks,
                );
            }
        }
    }
}
