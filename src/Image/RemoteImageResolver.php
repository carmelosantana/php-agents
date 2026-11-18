<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Image;

use CarmeloSantana\PHPAgents\Contract\ImageResolverInterface;
use CarmeloSantana\PHPAgents\Image\Internal\PublicImageDownloader;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads an http(s) image from a public address only, then checks the bytes against
 * the ImagePolicy (the sniffed MIME type is returned; the server's Content-Type is not
 * trusted beyond requiring image/*).
 *
 * Every hop is checked and pinned to a global address, proxies are bypassed, redirects
 * are followed by hand up to $maxRedirects and revalidated, and each hop is held to
 * $idleTimeout, $maxDuration and the policy's maxBytes. $client defaults to a
 * library-owned HttpClient::create(); pass one only when it honours `resolve`,
 * `max_redirects`, `no_proxy` and `on_progress`.
 */
final class RemoteImageResolver implements ImageResolverInterface
{
    /**
     * Test seam for the DNS lookup: host name to its addresses. Null uses real DNS.
     * Private and set only by reflection in tests; not public API.
     *
     * @var (\Closure(string): list<string>)|null
     */
    private ?\Closure $hostResolver = null; // @phpstan-ignore property.unusedType (assigned by reflection in tests)

    public function __construct(
        private readonly ?HttpClientInterface $client = null,
        private readonly ImagePolicy $policy = new ImagePolicy(),
        private readonly float $idleTimeout = 10.0,
        private readonly float $maxDuration = 30.0,
        private readonly int $maxRedirects = 3,
    ) {}

    public function resolve(string $reference): ResolvedImage
    {
        $scheme = strtolower((string) parse_url($reference, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ImageInputException('Remote image URL must be http or https.');
        }

        $downloader = new PublicImageDownloader($this->client, null, $this->hostResolver, $this->maxRedirects);
        $image = $downloader->download($reference, new RequestLimits(
            idleTimeout: $this->idleTimeout,
            maxDuration: $this->maxDuration,
            maxResponseBytes: $this->policy->maxBytes,
        ));
        if ($image === null) {
            throw new ImageInputException('Remote image was refused or could not be downloaded.');
        }

        return new ResolvedImage($this->policy->check($image['bytes'], $image['mimeType']), $image['bytes']);
    }
}
