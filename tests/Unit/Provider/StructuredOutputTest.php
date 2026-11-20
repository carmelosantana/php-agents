<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;
use CarmeloSantana\PHPAgents\Provider\StructuredOutput;

function structuredReturning(mixed $raw, ?array &$seen = null): ProviderInterface
{
    $seen = [];

    return new class($raw, $seen) implements ProviderInterface {
        use StreamsEventsFromResponses;

        public function __construct(private mixed $raw, private ?array &$seen) {}

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            $this->seen = ['schema' => $schema, 'options' => $options];

            return $this->raw;
        }

        public function chat(array $messages, array $tools = [], array $options = []): Response { throw new LogicException(); }
        public function stream(array $messages, array $tools = [], array $options = []): iterable { throw new LogicException(); }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test'; }
        public function withModel(string $model): static { return $this; }
    };
}

$schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];

test('extract() takes an array schema and returns a decoded array provider result as is', function () use ($schema) {
    $provider = structuredReturning(['name' => 'Jane'], $seen);

    expect(StructuredOutput::extract($provider, [new UserMessage('Jane')], $schema, ['temperature' => 0]))->toBe(['name' => 'Jane'])
        ->and(json_decode($seen['schema'], true))->toBe($schema)
        ->and($seen['options'])->toBe(['temperature' => 0]);
});

test('extract() accepts a JSON string schema unchanged', function () {
    $provider = structuredReturning(['ok' => true], $seen);

    StructuredOutput::extract($provider, [], '{"type":"object"}');

    expect($seen['schema'])->toBe('{"type":"object"}');
});

test('extract() decodes the JSON text of a Response', function () use ($schema) {
    $provider = structuredReturning(new Response(content: '{"name":"Jane"}', finishReason: ProviderFinishReason::Stop));

    expect(StructuredOutput::extract($provider, [], $schema))->toBe(['name' => 'Jane']);
});

test('decode() unwraps a fenced JSON reply', function () {
    $raw = new Response(content: "```json\n{\"name\":\"Jane\"}\n```", finishReason: ProviderFinishReason::Stop);

    expect(StructuredOutput::decode($raw))->toBe(['name' => 'Jane']);
});

test('decode() returns [] for anything that is not a JSON object or array', function (mixed $raw) {
    expect(StructuredOutput::decode($raw))->toBe([]);
})->with([
    'null' => [null],
    'string' => ['{"name":"Jane"}'],
    'prose' => [new Response(content: 'Sure! Jane.', finishReason: ProviderFinishReason::Stop)],
    'scalar json' => [new Response(content: '42', finishReason: ProviderFinishReason::Stop)],
]);
