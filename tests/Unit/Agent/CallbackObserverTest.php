<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Agent\CallbackObserver;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use Tests\Support\Agent\ScriptedProvider;

test('CallbackObserver streams only the events it names', function () {
    $agent = new Agent(new ScriptedProvider([new Response(content: 'Hello', finishReason: ProviderFinishReason::Stop)]));
    $seen = [];
    $agent->attach(new CallbackObserver(function (string $event, mixed $data) use (&$seen): void {
        $seen[] = [$event, $data];
    }, 'agent.text_delta'));

    $agent->run(new UserMessage('Hi'));

    expect($seen)->toBe([['agent.text_delta', 'Hello']]);
});

test('CallbackObserver with no event names receives every event', function () {
    $agent = new Agent(new ScriptedProvider([new Response(content: 'Hello', finishReason: ProviderFinishReason::Stop)]));
    $events = [];
    $agent->attach(new CallbackObserver(function (string $event) use (&$events): void {
        $events[] = $event;
    }));

    $agent->run(new UserMessage('Hi'));

    expect($events)->toContain('agent.start', 'agent.text_delta', 'agent.done');
});
