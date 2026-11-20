<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\Internal\MimeSniffer;
use Tests\Support\Image\ImageFixtures;

// Kanboard's PHP image (and other slim builds) ship without ext-fileinfo, so `new \finfo`
// is a fatal "Class not found". The sniffer falls back to getimagesizefromstring().

dataset('images', [
    'png' => [fn() => ImageFixtures::png(), 'image/png'],
    'jpeg' => [fn() => ImageFixtures::jpeg(), 'image/jpeg'],
    'gif' => [fn() => ImageFixtures::gif(), 'image/gif'],
    'webp' => [fn() => ImageFixtures::webp(), 'image/webp'],
]);

test('without finfo the sniffer reads the image type with getimagesizefromstring', function (Closure $bytes, string $mime) {
    expect(MimeSniffer::sniff($bytes(), useFinfo: false))->toBe($mime);
})->with('images');

test('with finfo the sniffer agrees with the fallback', function (Closure $bytes, string $mime) {
    expect(MimeSniffer::sniff($bytes(), useFinfo: true))->toBe($mime);
})->with('images')->skip(!class_exists(\finfo::class), 'ext-fileinfo is not loaded');

test('without finfo bytes that are not an image sniff to null, quietly', function (string $bytes) {
    expect(MimeSniffer::sniff($bytes, useFinfo: false))->toBeNull();
})->with(['plain text' => 'just some text', 'php' => '<?php echo 1;', 'one byte' => "\x89"]);

test('the sniffer uses finfo only when the class exists', function () {
    expect(MimeSniffer::finfoAvailable())->toBe(class_exists(\finfo::class));
});
