<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent\Event;

/**
 * Payload of agent.done_delta: newly decoded text of a streaming `done` call's `response`
 * argument. Provisional: agent.done accepts it, agent.tool_input_discarded for $callId retracts it.
 * agent.done carries the authoritative `response`; a UI renders that in place of the preview.
 * The two can differ when the JSON repeats the `response` key: the preview reads the first,
 * the decoded arguments keep the last.
 */
final readonly class DonePreview
{
    public function __construct(
        public string $callId,
        public string $delta,
    ) {}
}
