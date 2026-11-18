<?php

declare(strict_types=1);

// docs/PROVIDERS.md passed an array schema to ProviderInterface::structured(), which takes a
// JSON string (a TypeError under strict_types), and showed its result as a decoded array,
// which only some providers return. The examples go through StructuredOutput::extract().

test('structured output examples use StructuredOutput::extract and the real signature', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');
    $param = (new ReflectionMethod(\CarmeloSantana\PHPAgents\Contract\ProviderInterface::class, 'structured'))->getParameters()[1];

    expect((string) $param->getType())->toBe('string')
        ->and($doc)->not->toContain('$provider->structured(')
        ->and($doc)->toContain('StructuredOutput::extract(')
        ->and($doc)->toContain('public function structured(array $messages, string $schema, array $options = []): mixed')
        ->and($doc)->not->toContain('public function structured(array $messages, array $schema');
});
