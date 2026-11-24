<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A response (200 unless told otherwise) that only answers what SseStreamParser asks of it.
 * getHeaders() throws symfony's ClientException or ServerException for a 4xx or 5xx.
 */
final class ScriptedResponse implements ResponseInterface
{
    public function __construct(private readonly string $url, private readonly int $status = 200) {}

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        if ($throw && $this->status >= 500) {
            throw new ServerException($this);
        }
        if ($throw && $this->status >= 400) {
            throw new ClientException($this);
        }

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
        $info = ['url' => $this->url, 'http_code' => $this->status, 'response_headers' => []];

        return $type === null ? $info : ($info[$type] ?? null);
    }
}
