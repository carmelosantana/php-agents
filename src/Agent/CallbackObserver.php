<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

use Closure;
use SplObserver;
use SplSubject;

/**
 * Turns a closure into an agent observer, so streaming text or watching tool calls
 * needs no SplObserver class:
 *
 *     $agent->attach(new CallbackObserver(fn(string $event, mixed $delta) => print $delta, 'agent.text_delta'));
 *
 * The closure receives (string $event, mixed $data). With no event names it receives every event.
 */
final class CallbackObserver implements SplObserver
{
    /** @var list<string> */
    private readonly array $events;

    public function __construct(
        private readonly Closure $listener,
        string ...$events,
    ) {
        $this->events = array_values($events);
    }

    public function update(SplSubject $subject): void
    {
        if (!$subject instanceof AbstractAgent) {
            return;
        }

        $event = $subject->lastEvent();
        if ($this->events !== [] && !in_array($event, $this->events, true)) {
            return;
        }

        ($this->listener)($event, $subject->lastEventData());
    }
}
