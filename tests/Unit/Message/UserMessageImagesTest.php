<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Image\LocalImageResolver;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use Tests\Support\Image\ImageFixtures;

// Pentest: UserMessage::withImages() read any path (e.g. /etc/passwd) into a data URI.
// Paths now need an ImageResolverInterface, which decides what may be read.

beforeEach(function () {
    $this->base = sys_get_temp_dir() . '/php-agents-withimages-' . bin2hex(random_bytes(4));
    $this->root = $this->base . '/root';
    $this->outside = $this->base . '/outside';
    mkdir($this->root, 0777, true);
    mkdir($this->outside, 0777, true);
});

afterEach(function () {
    foreach ([$this->root, $this->outside] as $dir) {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
    @rmdir($this->base);
});

test('withImages without a resolver refuses a path such as /etc/passwd', function () {
    expect(fn() => UserMessage::withImages('Describe', ['/etc/passwd']))
        ->toThrow(ImageInputException::class, 'Image paths need an ImageResolverInterface');
});

test('withImages with no paths and no resolver is a text-only message', function () {
    expect(UserMessage::withImages('Describe', [])->content())->toBe([['type' => 'text', 'text' => 'Describe']]);
});

test('withImages keeps a real png and labels it image/png', function () {
    $path = $this->root . '/photo.jpg';
    file_put_contents($path, ImageFixtures::png());

    $content = UserMessage::withImages('Describe', [$path], new LocalImageResolver([$this->root]))->content();

    expect($content)->toHaveCount(2)
        ->and($content[1])->toBe([
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/png;base64,' . base64_encode(ImageFixtures::png())],
        ]);
});

test('withImages with a resolver refuses a non-image inside its root', function () {
    $path = $this->root . '/passwd';
    file_put_contents($path, "root:x:0:0:root:/root:/bin/bash\n");

    expect(fn() => UserMessage::withImages('Describe', [$path], new LocalImageResolver([$this->root])))
        ->toThrow(ImageInputException::class, 'is not allowed');
});

test('withImages with a resolver refuses a path outside its root', function () {
    $path = $this->outside . '/photo.png';
    file_put_contents($path, ImageFixtures::png());

    expect(fn() => UserMessage::withImages('Describe', [$path], new LocalImageResolver([$this->root])))
        ->toThrow(ImageInputException::class, 'outside the allowed roots');
});

test('withImages with a resolver refuses a symlink inside its root to an outside png', function () {
    $target = $this->outside . '/photo.png';
    file_put_contents($target, ImageFixtures::png());
    $link = $this->root . '/link.png';
    symlink($target, $link);

    expect(fn() => UserMessage::withImages('Describe', [$link], new LocalImageResolver([$this->root])))
        ->toThrow(ImageInputException::class, 'outside the allowed roots');
});
