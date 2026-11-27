<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\UsageListener;
use CarmeloSantana\PHPAgents\Contract\UsageReporting;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Exception\ProviderException;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamProjector;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class AbstractProvider implements ProviderInterface, UsageReporting
{
    protected const int OPENAI_MAX_TOOLS = 128;

    protected string $model;
    protected string $baseUrl;
    protected string $apiKey;
    protected HttpClientInterface $httpClient;
    protected ?LoggerInterface $logger;

    private ?UsageListener $usageListener = null;

    /** Time, size and redirect limits that every request of this provider passes through apply(). */
    protected RequestLimits $requestLimits;

    public function __construct(
        string $model,
        string $baseUrl,
        string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->httpClient = $httpClient ?? HttpClient::create();
        $this->logger = $logger;
        $this->requestLimits = new RequestLimits();
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /**
     * Return a copy whose later requests use $limits. The original keeps its limits.
     */
    public function withRequestLimits(RequestLimits $limits): static
    {
        $clone = clone $this;
        $clone->requestLimits = $limits;

        return $clone;
    }

    public function requestLimits(): RequestLimits
    {
        return $this->requestLimits;
    }

    public function withUsageListener(UsageListener $listener): static
    {
        $clone = clone $this;
        $clone->usageListener = $listener;

        return $clone;
    }

    protected function reportUsage(UsageReport $report): void
    {
        try {
            $this->usageListener?->onUsage($report);
        } catch (\Throwable $e) {
            try {
                $this->logger?->warning('Usage listener failed.', ['exception_class' => $e::class]);
            } catch (\Throwable) {
                // Logging is best-effort so inference outcomes are preserved.
            }
        }
    }

    /** @param array<string, mixed> $options */
    protected function usageOperation(array &$options, string $own): string
    {
        $operation = $options[UsageReport::OPERATION_OPTION] ?? $own;
        unset($options[UsageReport::OPERATION_OPTION]);

        return $operation;
    }

    protected function startClock(): int
    {
        return (int) hrtime(true);
    }

    protected function elapsedMs(int $start): int
    {
        return intdiv($this->startClock() - $start, 1_000_000);
    }

    protected static function finishedFailed(ProviderFinishReason $r): bool
    {
        return $r === ProviderFinishReason::Error;
    }

    /**
     * The normalised projection of streamEvents(): one Response per text or reasoning delta,
     * then exactly one terminal Response carrying the finish reason, usage, model, tool calls
     * and content blocks. Providers implement streamEvents(); this shape is the same for all.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<Response>
     */
    final public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        return StreamProjector::toResponses($this->streamEvents($messages, $tools, $options));
    }

    /**
     * Stream the reply as typed events, ending with exactly one Finish.
     *
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<StreamEvent>
     */
    abstract public function streamEvents(array $messages, array $tools = [], array $options = []): iterable;

    /**
     * Decode a JSON response body. A 3xx is not followed (max_redirects is 0 by default),
     * so it surfaces as a ProviderException that says the redirect was refused, with
     * symfony's RedirectionException as its previous.
     *
     * @return array<string, mixed>
     */
    protected function decodeResponse(ResponseInterface $response): array
    {
        try {
            return $response->toArray();
        } catch (RedirectionExceptionInterface $e) {
            throw ProviderException::redirectRefused($e);
        }
    }

    /**
     * Build HTTP headers for API requests.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($this->apiKey !== '') {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        return $headers;
    }

    /**
     * Trim a provider tool list to a supported maximum and emit warning telemetry.
     *
     * @param ToolInterface[] $tools
     * @return ToolInterface[]
     */
    protected function trimToolsToLimit(array $tools, int $maxTools, string $provider, string $endpoint): array
    {
        if (count($tools) <= $maxTools) {
            return $tools;
        }

        $trimmed = array_slice($tools, 0, $maxTools);

        $this->logger?->warning(
            '{provider} tool list exceeded the maximum supported size for {endpoint}; trimming tools before sending the request.',
            [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'tool_count' => count($tools),
                'tool_limit' => $maxTools,
                'trimmed_count' => count($trimmed),
            ],
        );

        return $trimmed;
    }

    /**
     * Convert ToolInterface[] to provider-specific tool definitions.
     *
     * @param ToolInterface[] $tools
     * @return array<array<string, mixed>>
     */
    abstract protected function formatTools(array $tools): array;

    /**
     * Convert MessageInterface[] to provider-specific message format.
     *
     * @param MessageInterface[] $messages
     * @return array<array<string, mixed>>
     */
    abstract protected function formatMessages(array $messages): array;

    /**
     * Parse provider response into a Response value object.
     *
     * @param array<string, mixed> $data
     */
    abstract protected function parseResponse(array $data): Response;

    /**
     * Yields $events and cancels $response however they end: completed, thrown, or abandoned
     * (the consumer stopped iterating), whatever the client does when the response is destroyed.
     * Only an HTTP status error (HttpStatusException) leaves the response open, for the caller to
     * read a bounded part of its error body or cancel it;
     * anything else after a successful status (a parser limit, a provider error event) cancels.
     *
     * @template T
     * @param \Generator<int, T, mixed, mixed> $events
     * @return \Generator<int, T, mixed, void>
     */
    protected static function cancelOnAbandon(ResponseInterface $response, \Generator $events): \Generator
    {
        $keepResponse = false;
        try {
            yield from $events;
        } catch (\Throwable $e) {
            $keepResponse = $e instanceof HttpExceptionInterface;

            throw $e;
        } finally {
            if (!$keepResponse) {
                $response->cancel();
            }
        }
    }
}
