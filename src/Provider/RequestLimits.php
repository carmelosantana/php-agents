<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Provider;

use CarmeloSantana\PHPAgents\Exception\ProviderException;

/**
 * Time, size and redirect limits for one provider HTTP request, applied to the
 * symfony/http-client options array by apply().
 *
 * - $idleTimeout goes out as `timeout`, the longest wait between two chunks.
 * - $maxDuration goes out as `max_duration`, the total time for the request; 0.0 leaves
 *   `max_duration` out, so the client's own default applies (none for HttpClient::create()).
 * - $maxResponseBytes caps the downloaded body; 0 means no cap.
 * - $maxRedirects goes out as `max_redirects`, replacing any caller value, so by default
 *   no redirect is followed and request headers such as an API key never reach a host
 *   a 3xx names.
 * - $tickInterval is how often, in seconds, a streaming request polls while no data arrives,
 *   yielding a Tick each time; 0.0 turns ticks off. apply() leaves it out.
 * - $maxToolArgumentBytes caps the argument JSON of one tool call; 0 means no cap.
 * - $maxToolArgumentDepth caps how deeply one tool call's arguments nest objects and arrays
 *   (`{"a":1}` is 1 deep); 0 means json_decode()'s default. A call over either cap is dropped
 *   like one whose arguments are not a JSON object (see ToolArguments). apply() leaves both out.
 *
 * Any negative value throws \InvalidArgumentException.
 *
 * The byte cap and the redirect count are enforced in an `on_progress` callback that
 * throws ProviderException. symfony/http-client wraps what that callback throws in its
 * own TransportException, so the caller sees a TransportExceptionInterface whose
 * previous is the ProviderException.
 */
final readonly class RequestLimits
{
    public const int DEFAULT_MAX_RESPONSE_BYTES = 33_554_432;

    /** 4 MiB: several times the JSON a model can write in one response at today's output-token limits. */
    public const int DEFAULT_MAX_TOOL_ARGUMENT_BYTES = 4_194_304;

    /** Far deeper than any tool schema nests, and well under json_decode()'s 512. */
    public const int DEFAULT_MAX_TOOL_ARGUMENT_DEPTH = 64;

    public function __construct(
        public float $idleTimeout = 300.0,
        public float $maxDuration = 600.0,
        public int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        public int $maxRedirects = 0,
        public float $tickInterval = 0.25,
        public int $maxToolArgumentBytes = self::DEFAULT_MAX_TOOL_ARGUMENT_BYTES,
        public int $maxToolArgumentDepth = self::DEFAULT_MAX_TOOL_ARGUMENT_DEPTH,
    ) {
        $limits = [
            'idleTimeout' => $idleTimeout,
            'maxDuration' => $maxDuration,
            'maxResponseBytes' => $maxResponseBytes,
            'maxRedirects' => $maxRedirects,
            'tickInterval' => $tickInterval,
            'maxToolArgumentBytes' => $maxToolArgumentBytes,
            'maxToolArgumentDepth' => $maxToolArgumentDepth,
        ];
        foreach ($limits as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('RequestLimits $%s must not be negative, got %s.', $name, $value));
            }
        }
    }

    /**
     * Returns $options with `timeout`, `max_duration`, `max_redirects` and `on_progress`
     * set from these limits. A caller `on_progress` is kept and runs before the checks.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function apply(array $options): array
    {
        $options['timeout'] = $this->idleTimeout;
        if ($this->maxDuration > 0.0) {
            $options['max_duration'] = $this->maxDuration;
        } else {
            unset($options['max_duration']);
        }
        $options['max_redirects'] = $this->maxRedirects;

        $previous = $options['on_progress'] ?? null;
        $maxBytes = $this->maxResponseBytes;
        $maxRedirects = $this->maxRedirects;

        $options['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use ($previous, $maxBytes, $maxRedirects): void {
            if (is_callable($previous)) {
                $previous($dlNow, $dlSize, $info);
            }
            if ($maxBytes > 0 && $dlNow > $maxBytes) {
                throw ProviderException::requestRefused(sprintf('response exceeded %d bytes', $maxBytes));
            }
            $redirects = $info['redirect_count'] ?? 0;
            if (is_int($redirects) && $redirects > $maxRedirects) {
                throw ProviderException::requestRefused(sprintf('redirect refused after %d allowed', $maxRedirects));
            }
        };

        return $options;
    }
}
