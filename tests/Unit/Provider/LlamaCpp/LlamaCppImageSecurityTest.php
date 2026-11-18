<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ImageResolverInterface;
use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Image\ImagePolicy;
use CarmeloSantana\PHPAgents\Image\ResolvedImage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppMultimodalNormalizer;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppProjectorResolver;
use CarmeloSantana\PHPAgents\Provider\LlamaCppProvider;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use Tests\Support\Image\ImageFixtures;
use Tests\Support\Runtime\FakeLocalModelRuntime;

// Pentest L2: the llama.cpp normalizer read any local path or URL in an image_url block.
// Paths and URLs now need a host-supplied ImageResolverInterface; data URIs are policy checked.

/** A resolver that records each reference and returns a real PNG. */
function llamaImageSpy(): ImageResolverInterface
{
    return new class implements ImageResolverInterface {
        /** @var list<string> */
        public array $calls = [];

        public function resolve(string $reference): ResolvedImage
        {
            $this->calls[] = $reference;

            return new ResolvedImage('image/png', ImageFixtures::png());
        }
    };
}

function llamaVisionProvider(
    FakeLocalModelRuntime $runtime,
    ?LlamaCppMultimodalNormalizer $normalizer = null,
    bool $supportsVision = true,
    int $maxImages = 1,
): LlamaCppProvider {
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'vision-security',
            name: 'Vision Security',
            supportsVision: $supportsVision,
            defaultTemplate: 'baseline',
            projectorPath: '/models/security.mmproj',
            extras: ['maxImages' => $maxImages],
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'seen')]]],
    );

    return new LlamaCppProvider('vision-security', $runtime, multimodalNormalizer: $normalizer);
}

/** @param list<string> $urls */
function llamaImageMessage(array $urls): UserMessage
{
    $content = [['type' => 'text', 'text' => 'Look']];
    foreach ($urls as $url) {
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
    }

    return new UserMessage($content);
}

test('a local path is refused without a resolver', function () {
    $runtime = new FakeLocalModelRuntime();
    $provider = llamaVisionProvider($runtime);

    expect(fn() => $provider->chat([llamaImageMessage(['/etc/passwd'])]))
        ->toThrow(ImageInputException::class, 'Local image paths are disabled');
    expect($runtime->lastRequest())->toBeNull();
});

test('a file url is refused without a resolver', function () {
    $runtime = new FakeLocalModelRuntime();
    $provider = llamaVisionProvider($runtime);

    expect(fn() => $provider->chat([llamaImageMessage(['file:///etc/passwd'])]))
        ->toThrow(ImageInputException::class, 'Local image paths are disabled');
    expect($runtime->lastRequest())->toBeNull();
});

test('an http url is refused without a resolver or fetcher', function () {
    $runtime = new FakeLocalModelRuntime();
    $provider = llamaVisionProvider($runtime);

    expect(fn() => $provider->chat([llamaImageMessage(['http://169.254.169.254/latest/meta-data/'])]))
        ->toThrow(ImageInputException::class, 'Remote image URLs are disabled');
    expect($runtime->lastRequest())->toBeNull();
});

test('no network or file read happens for a non-vision model', function () {
    $spy = llamaImageSpy();
    $provider = llamaVisionProvider(
        new FakeLocalModelRuntime(),
        new LlamaCppMultimodalNormalizer(new LlamaCppProjectorResolver(), null, $spy),
        supportsVision: false,
    );

    expect(fn() => $provider->chat([llamaImageMessage(['/srv/images/a.png'])]))
        ->toThrow(InvalidArgumentException::class, 'does not support image input');
    expect($spy->calls)->toBe([]);
});

test('image count is checked before any resolve', function () {
    $spy = llamaImageSpy();
    $provider = llamaVisionProvider(
        new FakeLocalModelRuntime(),
        new LlamaCppMultimodalNormalizer(new LlamaCppProjectorResolver(), null, $spy),
        maxImages: 1,
    );

    expect(fn() => $provider->chat([llamaImageMessage(['/srv/images/a.png', 'https://example.com/b.png'])]))
        ->toThrow(InvalidArgumentException::class, 'at most 1 image(s)');
    expect($spy->calls)->toBe([]);
});

test('a configured resolver supplies path and url images', function () {
    $spy = llamaImageSpy();
    $runtime = new FakeLocalModelRuntime();
    $provider = llamaVisionProvider(
        $runtime,
        new LlamaCppMultimodalNormalizer(new LlamaCppProjectorResolver(), null, $spy),
        maxImages: 2,
    );

    $provider->chat([llamaImageMessage(['/srv/images/a.png', 'https://example.com/b.png'])]);

    expect($spy->calls)->toBe(['/srv/images/a.png', 'https://example.com/b.png'])
        ->and($runtime->lastRequest()?->images)->toHaveCount(2)
        ->and($runtime->lastRequest()?->images[1]->mimeType)->toBe('image/png');
});

test('an oversized data uri is refused before decoding', function () {
    $provider = llamaVisionProvider(
        new FakeLocalModelRuntime(),
        new LlamaCppMultimodalNormalizer(new LlamaCppProjectorResolver(), imagePolicy: new ImagePolicy(maxBytes: 16)),
    );
    $uri = 'data:image/png;base64,' . base64_encode(str_repeat('A', 768));

    expect(strlen(base64_encode(str_repeat('A', 768))))->toBe(1024);

    try {
        $provider->chat([llamaImageMessage([$uri])]);
        $this->fail('Expected an ImageInputException.');
    } catch (ImageInputException $e) {
        expect($e->getMessage())->toContain('larger than')
            ->and($e->getMessage())->not->toContain('QUFB');
    }
});

test('a data uri that is not an image is refused', function () {
    $provider = llamaVisionProvider(new FakeLocalModelRuntime());
    $payload = base64_encode('root:x:0:0');

    try {
        $provider->chat([llamaImageMessage(['data:image/png;base64,' . $payload])]);
        $this->fail('Expected an ImageInputException.');
    } catch (ImageInputException $e) {
        expect($e->getMessage())->toContain('is not allowed')
            ->and($e->getMessage())->not->toContain($payload);
    }
});

test('a data uri image is labelled with its sniffed mime type', function () {
    $runtime = new FakeLocalModelRuntime();
    $provider = llamaVisionProvider($runtime);

    $provider->chat([llamaImageMessage(['data:image/jpeg;base64,' . base64_encode(ImageFixtures::png())])]);

    expect($runtime->lastRequest()?->images[0]->mimeType)->toBe('image/png')
        ->and($runtime->lastRequest()?->images[0]->bytes)->toBe(ImageFixtures::png());
});

test('the legacy fetcher output is policy checked', function () {
    $provider = llamaVisionProvider(
        new FakeLocalModelRuntime(),
        new LlamaCppMultimodalNormalizer(new LlamaCppProjectorResolver(), static fn(string $url): string => 'text'),
    );

    expect(fn() => $provider->chat([llamaImageMessage(['https://example.com/a.png'])]))
        ->toThrow(ImageInputException::class, 'is not allowed');
});

test('a data uri that is not base64 is refused and never read as a path', function () {
    $spy = llamaImageSpy();
    $provider = llamaVisionProvider(
        new FakeLocalModelRuntime(),
        new LlamaCppMultimodalNormalizer(new LlamaCppProjectorResolver(), null, $spy),
    );

    expect(fn() => $provider->chat([llamaImageMessage(['data:image/png,root:x:0:0'])]))
        ->toThrow(ImageInputException::class, 'must be base64 encoded');
    expect($spy->calls)->toBe([]);
});

test('a data uri with media type parameters is accepted', function () {
    $runtime = new FakeLocalModelRuntime();
    $provider = llamaVisionProvider($runtime);

    $provider->chat([llamaImageMessage(['data:image/png;name=a.png;base64,' . base64_encode(ImageFixtures::png())])]);

    expect($runtime->lastRequest()?->images[0]->bytes)->toBe(ImageFixtures::png());
});
