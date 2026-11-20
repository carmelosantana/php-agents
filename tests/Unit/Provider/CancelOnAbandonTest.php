<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// AbstractProvider::cancelOnAbandon() wraps a streaming provider's event generator so the HTTP
// response is cancelled however the generator ends, except on an HTTP status error, whose body
// the caller still reads.

/** A response that counts cancel() calls. */
function cancelOnAbandonSpy(): ResponseInterface
{
    return new class implements ResponseInterface {
        public int $cancels = 0;

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

        public function cancel(): void
        {
            $this->cancels++;
        }

        public function getInfo(?string $type = null): mixed
        {
            return null;
        }
    };
}

function cancelOnAbandon(ResponseInterface $response, Generator $events): Generator
{
    return (new ReflectionMethod(AbstractProvider::class, 'cancelOnAbandon'))->invoke(null, $response, $events);
}

test('it yields every event and cancels once the stream completes', function () {
    $response = cancelOnAbandonSpy();

    $events = iterator_to_array(cancelOnAbandon($response, (fn(): Generator => yield from ['a', 'b'])()), false);

    expect($events)->toBe(['a', 'b'])
        ->and($response->cancels)->toBe(1);
});

test('it cancels when the consumer abandons the stream', function () {
    $response = cancelOnAbandonSpy();
    $stream = cancelOnAbandon($response, (fn(): Generator => yield from ['a', 'b', 'c'])());

    expect($stream->current())->toBe('a');
    unset($stream);

    expect($response->cancels)->toBe(1);
});

test('it cancels and rethrows when the stream throws', function () {
    $response = cancelOnAbandonSpy();
    $stream = cancelOnAbandon($response, (function (): Generator {
        yield 'a';

        throw new RuntimeException('parser limit');
    })());

    expect(fn() => iterator_to_array($stream))->toThrow(RuntimeException::class, 'parser limit')
        ->and($response->cancels)->toBe(1);
});

test('it leaves the response open on an HTTP status error, for the caller to read', function () {
    $response = cancelOnAbandonSpy();
    $stream = cancelOnAbandon($response, (function (): Generator {
        yield 'a';

        throw new class ('HTTP 401') extends RuntimeException implements HttpExceptionInterface {
            public function getResponse(): ResponseInterface
            {
                return cancelOnAbandonSpy();
            }
        };
    })());

    expect(fn() => iterator_to_array($stream))->toThrow(RuntimeException::class, 'HTTP 401')
        ->and($response->cancels)->toBe(0);
});
