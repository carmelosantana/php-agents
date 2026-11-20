<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\Internal\PublicImageDownloader;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;

final class ImageDownloaderLogRecorder extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = (string) $message . ' ' . json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}

test('a refused image URL is logged as scheme, host and a redacted path only', function () {
    $logger = new ImageDownloaderLogRecorder();
    $downloader = new PublicImageDownloader(new MockHttpClient([]), $logger);

    $result = $downloader->download('http://alice:hunter2@127.0.0.1:8080/private/sig-abc123/cat.png?token=qwerty#frag', new RequestLimits());

    expect($result)->toBeNull()
        ->and($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0])->toContain('http://127.0.0.1:8080/[redacted]')
        ->and($logger->lines[0])->not->toContain('alice')
        ->and($logger->lines[0])->not->toContain('hunter2')
        ->and($logger->lines[0])->not->toContain('sig-abc123')
        ->and($logger->lines[0])->not->toContain('qwerty')
        ->and($logger->lines[0])->not->toContain('frag');
});

test('a download failure never logs a credential or control character from its exception text', function () {
    $logger = new ImageDownloaderLogRecorder();
    $secret = 'sk-' . str_repeat('A1b2', 8);
    $client = new MockHttpClient(function () use ($secret): never {
        throw new TransportException("boom \e[31m for https://img.example/x?key={$secret}");
    });
    $downloader = new PublicImageDownloader($client, $logger, static fn(string $host): array => ['93.184.216.34']);

    $result = $downloader->download('https://img.example/x.png', new RequestLimits());

    expect($result)->toBeNull()
        ->and($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0])->not->toContain($secret)
        ->and($logger->lines[0])->not->toContain("\e")
        ->and($logger->lines[0])->toContain('[redacted]');
});

test('a download failure logs the exception class, a fixed reason and the loggable URL, never its message', function () {
    $logger = new ImageDownloaderLogRecorder();
    $client = new MockHttpClient(function (): never {
        throw new TransportException('Idle timeout reached for "https://user:pass@img.example/private/photo.png?X-Amz-Signature=deadbeef1234&sig=s3cr3tvalue".');
    });
    $downloader = new PublicImageDownloader($client, $logger, static fn(string $host): array => ['93.184.216.34']);

    $downloader->download('https://user:pass@img.example/private/photo.png?X-Amz-Signature=deadbeef1234&sig=s3cr3tvalue', new RequestLimits());

    expect($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0])->toContain('TransportException')
        ->and($logger->lines[0])->toContain('https://img.example/[redacted]')
        ->and($logger->lines[0])->not->toContain('X-Amz-Signature')
        ->and($logger->lines[0])->not->toContain('deadbeef1234')
        ->and($logger->lines[0])->not->toContain('s3cr3tvalue')
        ->and($logger->lines[0])->not->toContain('user:pass')
        ->and($logger->lines[0])->not->toContain('private')
        ->and($logger->lines[0])->not->toContain('Idle timeout');
});
