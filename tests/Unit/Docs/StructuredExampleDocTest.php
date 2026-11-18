<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;

// structured() takes the schema as a JSON string. This pins every structured()
// example in docs/PROVIDERS.md to pass one, so an example that passes a PHP
// array (a TypeError under strict_types) fails here.

test('every structured() example in PROVIDERS.md passes the schema as a JSON string', function () {
    $doc = (string) file_get_contents(__DIR__ . '/../../../docs/PROVIDERS.md');
    $schema = (new ReflectionMethod(ProviderInterface::class, 'structured'))->getParameters()[1];

    preg_match_all('/->structured\(\n(?:.*\n)*?\s*schema: (.*)\n/', $doc, $matches);

    expect($schema->getName())->toBe('schema')
        ->and((string) $schema->getType())->toBe('string')
        ->and($matches[1])->toHaveCount(2);

    foreach ($matches[1] as $argument) {
        expect($argument)->toBe('json_encode([');
    }
});
