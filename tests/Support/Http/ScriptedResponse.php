<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;

/** A 200 response that only answers what SseStreamParser asks of it. */
final class ScriptedResponse implements ResponseInterface
{
    public function __construct(private readonly string $url) {}

    public function getStatusCode(): int
    {
        return 200;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void {}

    public function getInfo(?string $type = null): mixed
    {
        $info = ['url' => $this->url, 'http_code' => 200];

        return $type === null ? $info : ($info[$type] ?? null);
    }
}
