<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Image\Internal;

use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads an image URL from a public address only.
 *
 * Each hop must be http(s) and its host must resolve only to global addresses
 * (FILTER_FLAG_GLOBAL_RANGE, plus the IPv6 DENIED_IPV6_RANGES), so loopback, private,
 * link-local, CGNAT, NAT64, site-local, multicast and other reserved ranges are never
 * requested. The checked address is pinned with the `resolve` option so the connection
 * goes to that address, and `no_proxy: *` keeps a proxy from re-resolving the host. The
 * client follows no redirects; download() follows up to $maxRedirects 3xx hops itself
 * and checks each Location the same way. Every hop is held to the given RequestLimits.
 *
 * The fetch goes through $client, or a library-owned HttpClient::create() when none was
 * given. A client that ignores `resolve`, `max_redirects`, `no_proxy` or `on_progress`
 * defeats this guard, so never pass a provider's API client.
 *
 * @internal Shared by GeminiProvider and RemoteImageResolver; not public API.
 */
final class PublicImageDownloader
{
    /**
     * @param (\Closure(string): list<string>)|null $hostResolver Host name to its addresses; null uses DNS. A test seam.
     */
    public function __construct(
        private readonly ?HttpClientInterface $client,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $hostResolver = null,
        private readonly int $maxRedirects = 3,
    ) {}

    /**
     * Returns the image's Content-Type MIME and raw bytes, or null when a hop is refused,
     * a 3xx has no Location, the redirect budget runs out, the status is not 2xx, the
     * content type is not image/*, the body is empty, or any exception is thrown.
     *
     * @return array{mimeType: string, bytes: string}|null
     */
    public function download(string $url, RequestLimits $limits): ?array
    {
        $client = $this->client ?? HttpClient::create();

        try {
            for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
                $pin = $this->publicHostPin($url);
                if ($pin === null) {
                    $this->logger?->debug('Refused image URL: {url}', ['url' => $url]);

                    return null;
                }

                $response = $client->request('GET', $url, $limits->apply([
                    'headers' => ['Accept' => 'image/*'],
                    'resolve' => $pin,
                    'proxy' => null,
                    'no_proxy' => '*',
                ]));

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 300 && $statusCode < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? '';
                    $response->cancel();
                    if ($location === '') {
                        return null;
                    }
                    $url = $this->resolveRedirectUrl($url, $location);
                    continue;
                }

                if ($statusCode < 200 || $statusCode >= 300) {
                    return null;
                }

                $contentType = $response->getHeaders()['content-type'][0] ?? '';
                $mime = strtolower(trim(explode(';', $contentType)[0]));
                if (!str_starts_with($mime, 'image/')) {
                    return null;
                }

                $body = $response->getContent();
                if ($body === '') {
                    return null;
                }

                return ['mimeType' => $mime, 'bytes' => $body];
            }

            $this->logger?->debug('Refused image URL after {max} redirects', ['max' => $this->maxRedirects]);

            return null;
        } catch (\Throwable $e) {
            $this->logger?->debug('Failed to download image from URL: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Returns the `resolve` option `[host => ip]` for an http(s) URL whose host resolves
     * only to global addresses (an empty array when the host is an IP literal), or null when the scheme is not http(s), the host does not
     * resolve, or any resolved address is not global.
     *
     * @return array<string, string>|null
     */
    private function publicHostPin(string $url): ?array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);
        if (($scheme !== 'http' && $scheme !== 'https') || !is_string($host) || $host === '') {
            return null;
        }

        $addresses = $this->resolveHostAddresses(trim($host, '[]'));
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false || self::inDeniedIpv6Range($ip)) {
                return null;
            }
        }

        // An IP literal has no DNS to rebind, and curl rejects a bracketed IPv6 resolve key.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return [$host => $addresses[0]];
    }

    /**
     * IPv6 ranges FILTER_FLAG_GLOBAL_RANGE accepts that can still reach a private or
     * non-unicast address: NAT64 (/96 well-known, /48 local-use), deprecated site-local,
     * multicast and the reserved ::/8 block. ::/8 holds IPv4-compatible (::/96, e.g.
     * ::7f00:1), IPv4-translated (::ffff:0:0:0/96, RFC 2765) and IPv4-mapped
     * (::ffff:0:0/96, which PHP's filter already refuses) addresses.
     */
    private const array DENIED_IPV6_RANGES = [
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['fec0::', 10],
        ['ff00::', 8],
        ['::', 8],
    ];

    /** True when $ip is an IPv6 address inside one of DENIED_IPV6_RANGES. */
    private static function inDeniedIpv6Range(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        foreach (self::DENIED_IPV6_RANGES as [$prefix, $bits]) {
            $range = (string) inet_pton($prefix);
            $bytes = intdiv($bits, 8);
            if (strncmp($packed, $range, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            $mask = $rest === 0 ? 0 : (0xFF << (8 - $rest)) & 0xFF;
            if ($rest === 0 || (ord($packed[$bytes]) & $mask) === (ord($range[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the host itself when it is an IP literal, otherwise the addresses
     * $hostResolver (by default lookupHostAddresses()) gives for it. Empty when nothing
     * resolves.
     *
     * @return list<string>
     */
    private function resolveHostAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return ($this->hostResolver ?? $this->lookupHostAddresses(...))($host);
    }

    /**
     * The host name's A records (gethostbynamel) followed by its AAAA records
     * (dns_get_record). Empty when nothing resolves.
     *
     * @return list<string>
     */
    private function lookupHostAddresses(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /**
     * Resolves a Location header against the URL that answered with it: an absolute URL
     * is returned as is, `//host/...` takes the current scheme, `/path` takes the
     * current origin, `?query` replaces the current query, and any other value replaces
     * the last segment of the current path.
     */
    private function resolveRedirectUrl(string $current, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:)(//[^/?\#]*)([^?\#]*)#i', $current, $m) !== 1) {
            return $location;
        }
        [, $scheme, $authority, $path] = $m;

        if (str_starts_with($location, '//')) {
            return $scheme . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . $authority . $location;
        }
        if (str_starts_with($location, '?')) {
            return $scheme . $authority . $path . $location;
        }

        $directory = substr($path, 0, (int) strrpos($path, '/'));

        return $scheme . $authority . $directory . '/' . $location;
    }
}
