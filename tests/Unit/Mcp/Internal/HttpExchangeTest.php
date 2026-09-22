<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Mcp\Internal\HttpExchange;
use CarmeloSantana\PHPAgents\Mcp\McpAuthException;
use CarmeloSantana\PHPAgents\Mcp\McpRedirectException;
use CarmeloSantana\PHPAgents\Mcp\McpServer;
use CarmeloSantana\PHPAgents\Mcp\McpTransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

function exchangeOver(HttpClientInterface $http, array $server = []): HttpExchange
{
    return new HttpExchange(new McpServer(...array_replace(['url' => 'https://mcp.example.test/mcp', 'headers' => ['Authorization' => 'Bearer s3cret']], $server)), $http);
}

test('posts one JSON body to the configured URL with its own safety options and the configured headers', function () {
    $seen = [];
    $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
        $seen = compact('method', 'url', 'options');

        return new MockResponse('{}', ['response_headers' => ['Content-Type: application/json']]);
    });

    $reply = exchangeOver($http, ['timeout' => 7.5])->post('tools/list', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Mcp-Method' => 'tools/list']);
    $headers = implode("\n", $seen['options']['headers']);

    expect($reply->status)->toBe(200)
        ->and($seen['method'])->toBe('POST')
        ->and($seen['url'])->toBe('https://mcp.example.test/mcp')
        ->and($seen['options']['body'])->toBe('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
        ->and($seen['options']['max_redirects'])->toBe(0)
        ->and($seen['options']['timeout'])->toBe(7.5)
        ->and($seen['options']['max_duration'])->toBe(7.5)
        ->and($seen['options']['on_progress'])->toBeCallable()
        ->and($headers)->toContain('Authorization: Bearer s3cret')
        ->and($headers)->toContain('Mcp-Method: tools/list')
        ->and($headers)->toContain('Content-Type: application/json')
        ->and($headers)->toContain('Accept: application/json, text/event-stream');
});

test('a 3xx is a redirect error and is never followed', function () {
    $http = new MockHttpClient([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://169.254.169.254/']])]);

    try {
        exchangeOver($http)->post('tools/list', [], []);
        $this->fail('expected a redirect error');
    } catch (McpRedirectException $e) {
        expect($e->status)->toBe(302)
            ->and($e->location)->toBe('https://169.254.169.254/')
            ->and($e->getMessage())->not->toContain('169.254');
    }
});

test('401 and 403 are auth errors', function (int $status) {
    $http = new MockHttpClient([new MockResponse('', ['http_code' => $status])]);

    expect(fn () => exchangeOver($http)->post('tools/call', [], []))->toThrow(McpAuthException::class);
})->with([401, 403]);

test('2xx, 400 and 404 come back as replies; other statuses are transport errors', function () {
    foreach ([200, 202, 400, 404] as $status) {
        $reply = exchangeOver(new MockHttpClient([new MockResponse('', ['http_code' => $status])]))->post('m', [], []);
        expect($reply->status)->toBe($status);
    }
    foreach ([405, 429, 500, 503] as $status) {
        expect(fn () => exchangeOver(new MockHttpClient([new MockResponse('', ['http_code' => $status])]))->post('m', [], []))
            ->toThrow(McpTransportException::class, "MCP m returned HTTP {$status}.");
    }
});

test('a timeout is a transport error that does not quote the URL', function () {
    $http = new MockHttpClient([new MockResponse([''])]);

    try {
        exchangeOver($http)->post('tools/list', [], []);
        $this->fail('expected a timeout');
    } catch (McpTransportException $e) {
        expect($e->getMessage())->toBe('MCP tools/list timed out.')
            ->and($e->getPrevious())->not->toBeNull();
    }
});

test('a body over the cap is refused', function () {
    $http = new MockHttpClient([new MockResponse([str_repeat('x', 600), str_repeat('x', 600)])]);

    expect(fn () => exchangeOver($http, ['maxResponseBytes' => 1000])->post('tools/list', [], []))
        ->toThrow(McpTransportException::class, 'MCP tools/list response exceeded 1000 bytes.');
});

test('the cap holds even when a wrapper drops on_progress', function () {
    $inner = new MockHttpClient([new MockResponse(str_repeat('x', 2000))]);
    $stripping = new class($inner) implements HttpClientInterface {
        public function __construct(private HttpClientInterface $inner) {}

        public function request(string $method, string $url, array $options = []): ResponseInterface
        {
            unset($options['on_progress']);

            return $this->inner->request($method, $url, $options);
        }

        public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
        {
            return $this->inner->stream($responses, $timeout);
        }

        public function withOptions(array $options): static
        {
            return $this;
        }
    };

    expect(fn () => exchangeOver($stripping, ['maxResponseBytes' => 1000])->post('tools/list', [], []))
        ->toThrow(McpTransportException::class, 'MCP tools/list response exceeded 1000 bytes.');
});

test('no error message carries a header value', function () {
    foreach ([new MockResponse('', ['http_code' => 500]), new MockResponse([''])] as $response) {
        try {
            exchangeOver(new MockHttpClient([$response]))->post('tools/list', [], ['Mcp-Session-Id' => 'sess-secret']);
        } catch (McpTransportException $e) {
            expect($e->getMessage())->not->toContain('s3cret')->not->toContain('sess-secret');
        }
    }
});
