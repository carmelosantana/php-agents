<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * An HTTP client whose stream() plays back one scripted poll per call, in wall-clock time.
 *
 * Each poll is a list of steps: a float sleeps that many seconds, self::TIMEOUT yields a timeout
 * chunk, a Closure is called (to count how far the consumer pulled, say) and yields nothing, and
 * a chunk is yielded as is. Like symfony, a poll's iterator ends only at a timeout or
 * last chunk: a poll that ends otherwise waits $tailWait seconds and times out. Once the script
 * runs out, every further poll does the same. The timeout passed to each stream() call is
 * recorded in $timeouts.
 */
final class ScriptedStreamClient implements HttpClientInterface
{
    public const string URL = 'https://stream.example.test/';

    public const string TIMEOUT = 'timeout';

    /** @var list<float|null> */
    public array $timeouts = [];

    public readonly ResponseInterface $response;

    /**
     * @param list<list<ChunkInterface|float|string|\Closure(): mixed>> $polls
     */
    public function __construct(private array $polls, private readonly float $tailWait = 0.01)
    {
        $this->response = new ScriptedResponse(self::URL);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $this->timeouts[] = $timeout;
        $poll = array_shift($this->polls) ?? [];
        $response = $this->response;
        $tailWait = $this->tailWait;

        return new ResponseStream((static function () use ($poll, $response, $tailWait): \Generator {
            foreach ($poll as $step) {
                if (is_float($step)) {
                    usleep((int) ($step * 1_000_000));
                } elseif ($step instanceof \Closure) {
                    $step();
                } elseif ($step === self::TIMEOUT) {
                    yield $response => self::timeoutChunk();

                    return;
                } else {
                    yield $response => $step;
                    if ($step instanceof ChunkInterface && $step->isLast()) {
                        return;
                    }
                }
            }
            usleep((int) ($tailWait * 1_000_000));
            yield $response => self::timeoutChunk();
        })());
    }

    private static function timeoutChunk(): ErrorChunk
    {
        return new ErrorChunk(0, sprintf('Idle timeout reached for "%s".', self::URL));
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
