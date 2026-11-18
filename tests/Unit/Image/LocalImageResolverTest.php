<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Image\ImagePolicy;
use CarmeloSantana\PHPAgents\Image\LocalImageResolver;
use Tests\Support\Image\ImageFixtures;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/php-agents-local-image-' . bin2hex(random_bytes(6));
    mkdir($this->root);
    $this->outside = sys_get_temp_dir() . '/php-agents-local-image-out-' . bin2hex(random_bytes(6));
    mkdir($this->outside);
});

afterEach(function () {
    foreach ([$this->root, $this->outside] as $dir) {
        foreach (array_merge(glob("{$dir}/*") ?: [], glob("{$dir}/.*") ?: []) as $entry) {
            if (is_link($entry) || is_file($entry) || filetype($entry) === 'fifo') {
                unlink($entry);
            } elseif (is_dir($entry) && !in_array(basename($entry), ['.', '..'], true)) {
                rmdir($entry);
            }
        }
        rmdir($dir);
    }
});

test('a path outside every allowed root is refused', function () {
    (new LocalImageResolver([$this->root]))->resolve('/etc/passwd');
})->throws(ImageInputException::class);

test('a symlink inside the root that points outside is refused', function () {
    file_put_contents("{$this->outside}/secret.png", ImageFixtures::png());
    symlink("{$this->outside}/secret.png", "{$this->root}/link.png");

    (new LocalImageResolver([$this->root]))->resolve("{$this->root}/link.png");
})->throws(ImageInputException::class);

test('a dot-dot path that escapes the root is refused', function () {
    file_put_contents("{$this->outside}/secret.png", ImageFixtures::png());

    (new LocalImageResolver([$this->root]))->resolve("{$this->root}/../" . basename($this->outside) . '/secret.png');
})->throws(ImageInputException::class);

test('a real png inside the root resolves', function () {
    file_put_contents("{$this->root}/a.png", ImageFixtures::png());

    $image = (new LocalImageResolver([$this->root]))->resolve("{$this->root}/a.png");

    expect($image->mimeType)->toBe('image/png')
        ->and($image->bytes)->toBe(ImageFixtures::png());
});

test('a file:// reference inside the root resolves', function () {
    file_put_contents("{$this->root}/a.png", ImageFixtures::png());

    $image = (new LocalImageResolver([$this->root]))->resolve("file://{$this->root}/a.png");

    expect($image->mimeType)->toBe('image/png');
});

test('a sibling directory sharing the root as a name prefix is refused', function () {
    $sibling = $this->root . '-sibling';
    mkdir($sibling);
    file_put_contents("{$sibling}/a.png", ImageFixtures::png());

    try {
        (new LocalImageResolver([$this->root]))->resolve("{$sibling}/a.png");
    } finally {
        unlink("{$sibling}/a.png");
        rmdir($sibling);
    }
})->throws(ImageInputException::class);

test('a text file renamed .png is refused', function () {
    file_put_contents("{$this->root}/a.png", "just some text\n");

    (new LocalImageResolver([$this->root]))->resolve("{$this->root}/a.png");
})->throws(ImageInputException::class);

test('a file over maxBytes is refused before it is read', function () {
    file_put_contents("{$this->root}/a.png", ImageFixtures::png());

    (new LocalImageResolver([$this->root], new ImagePolicy(maxBytes: 10)))->resolve("{$this->root}/a.png");
})->throws(ImageInputException::class, 'size');

test('a file that holds more bytes than its size says is refused by the read cap', function () {
    // /proc files stat as regular, size 0, yet read back content: the same shape as a file
    // that grows after the size check. The read cap is what refuses it.
    if (!is_file('/proc/self/status')) {
        $this->markTestSkipped('needs /proc');
    }

    (new LocalImageResolver(['/proc/self'], new ImagePolicy(maxBytes: 10)))->resolve('/proc/self/status');
})->throws(ImageInputException::class, 'more than');

test('no roots refuses everything', function () {
    file_put_contents("{$this->root}/a.png", ImageFixtures::png());

    (new LocalImageResolver([]))->resolve("{$this->root}/a.png");
})->throws(ImageInputException::class);

test('a root that does not exist is ignored', function () {
    file_put_contents("{$this->root}/a.png", ImageFixtures::png());

    $image = (new LocalImageResolver(['/does/not/exist', $this->root]))->resolve("{$this->root}/a.png");

    expect($image->mimeType)->toBe('image/png');
});

test('a directory is refused', function () {
    mkdir("{$this->root}/dir.png");

    (new LocalImageResolver([$this->root]))->resolve("{$this->root}/dir.png");
})->throws(ImageInputException::class);

test('a FIFO is refused without blocking', function () {
    if (!function_exists('posix_mkfifo')) {
        $this->markTestSkipped('posix_mkfifo is missing');
    }
    posix_mkfifo("{$this->root}/pipe.png", 0600);

    (new LocalImageResolver([$this->root]))->resolve("{$this->root}/pipe.png");
})->throws(ImageInputException::class);

test('a symlink in the root to a device is refused', function () {
    symlink('/dev/zero', "{$this->root}/zero.png");

    (new LocalImageResolver([$this->root]))->resolve("{$this->root}/zero.png");
})->throws(ImageInputException::class);
