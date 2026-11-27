<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Exception\ClientHttpStatusException;
use CarmeloSantana\PHPAgents\Exception\HttpStatusException;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Exception\ServerHttpStatusException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

// The upgrade guide and the streaming docs name the status-only exception for streamed HTTP
// errors, its symfony interfaces and the cancel() call. These check the names they use.

$root = dirname(__DIR__, 3);

test('the 0.21.0 upgrade row and the streaming docs describe the status-only exception', function () use ($root) {
    $upgrading = (string) file_get_contents($root . '/docs/UPGRADING.md');
    $section = substr($upgrading, (int) strpos($upgrading, "## Upgrading to 0.21.0\n"));
    $section = substr($section, 0, (int) strpos($section, "\n## Upgrading to 0.20.1"));
    $providers = (string) file_get_contents($root . '/docs/PROVIDERS.md');

    expect($section)->toContain('`Exception\HttpStatusException`')
        ->and($section)->toContain('`Provider returned HTTP 429`')
        ->and($section)->toContain('`getResponse()->cancel()`')
        ->and($providers)->toContain('`$e->getResponse()->cancel()`')
        ->and($providers)->toContain('(`Provider returned HTTP 429`)');
});

test('the documented classes extend ProviderException and implement the symfony interfaces', function () {
    expect(is_subclass_of(HttpStatusException::class, ProviderException::class))->toBeTrue()
        ->and(is_subclass_of(ClientHttpStatusException::class, ClientExceptionInterface::class))->toBeTrue()
        ->and(is_subclass_of(ServerHttpStatusException::class, ServerExceptionInterface::class))->toBeTrue()
        ->and(method_exists(HttpStatusException::class, 'getResponse'))->toBeTrue()
        ->and(method_exists(HttpStatusException::class, 'getStatusCode'))->toBeTrue();
});
