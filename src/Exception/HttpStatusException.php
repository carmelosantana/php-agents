<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Exception;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A 4xx or 5xx answer to a streamed request, thrown before any of the body is read.
 *
 * The message names the status only ("Provider returned HTTP 429"). The response is left
 * open so a caller can read a bounded part of the error body; a caller that does not want
 * the body calls `getResponse()->cancel()`. A 4xx is a ClientHttpStatusException
 * (Symfony's ClientExceptionInterface), a 5xx a ServerHttpStatusException
 * (ServerExceptionInterface).
 */
abstract class HttpStatusException extends ProviderException implements HttpExceptionInterface
{
    final protected function __construct(private readonly ResponseInterface $response, private readonly int $statusCode)
    {
        parent::__construct(sprintf('Provider returned HTTP %d', $statusCode), $statusCode);
    }

    /** The exception for $response's status, which is 400 or above. */
    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();

        return $status >= 500
            ? new ServerHttpStatusException($response, $status)
            : new ClientHttpStatusException($response, $status);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
