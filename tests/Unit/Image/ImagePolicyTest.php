<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\ImagePolicy;
use Tests\Support\Image\ImageFixtures;

// maxBytes 0 would reach RemoteImageResolver as maxResponseBytes 0, which RequestLimits reads
// as "no cap". A policy always has a positive byte limit.

test('ImagePolicy refuses a byte limit below 1', function (int $maxBytes) {
    new ImagePolicy(maxBytes: $maxBytes);
})->throws(InvalidArgumentException::class, 'maxBytes must be at least 1.')->with([0, -1]);

test('ImagePolicy accepts a one-byte limit', function () {
    expect((new ImagePolicy(maxBytes: 1))->maxBytes)->toBe(1);
});

// `php -n` loads no php.ini, so shared extensions such as fileinfo stay off: the same
// "Class finfo not found" runtime Kanboard's PHP image has.
function checkWithoutFileinfo(string $bytes, string $allowed = 'image/png,image/jpeg,image/gif,image/webp'): string
{
    $script = sprintf(
        'require %s; if (class_exists("finfo")) { echo "FINFO_LOADED"; exit; } try { echo (new %s(allowedMimeTypes: explode(",", %s)))->check(base64_decode(%s)); } catch (%s $e) { echo "REFUSED: ", $e->getMessage(); }',
        var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
        '\CarmeloSantana\PHPAgents\Image\ImagePolicy',
        var_export($allowed, true),
        var_export(base64_encode($bytes), true),
        '\CarmeloSantana\PHPAgents\Image\ImageInputException',
    );
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($script) . ' 2>&1');

    return is_string($output) ? $output : '';
}

test('ImagePolicy checks the type on a PHP without fileinfo', function (Closure $bytes, string $mime) {
    expect(checkWithoutFileinfo($bytes()))->toBe($mime);
})->with([
    'png' => [fn() => ImageFixtures::png(), 'image/png'],
    'jpeg' => [fn() => ImageFixtures::jpeg(), 'image/jpeg'],
    'gif' => [fn() => ImageFixtures::gif(), 'image/gif'],
    'webp' => [fn() => ImageFixtures::webp(), 'image/webp'],
]);

test('ImagePolicy without fileinfo still refuses a type not on the allow-list', function () {
    expect(checkWithoutFileinfo(ImageFixtures::gif(), 'image/png'))->toBe('REFUSED: Image type image/gif is not allowed.');
});

test('ImagePolicy without fileinfo refuses bytes that are not an image', function () {
    expect(checkWithoutFileinfo('not an image'))->toBe('REFUSED: Image type unknown is not allowed.');
});
