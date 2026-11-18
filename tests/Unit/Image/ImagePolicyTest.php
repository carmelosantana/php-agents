<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Image\ImagePolicy;

// maxBytes 0 would reach RemoteImageResolver as maxResponseBytes 0, which RequestLimits reads
// as "no cap". A policy always has a positive byte limit.

test('ImagePolicy refuses a byte limit below 1', function (int $maxBytes) {
    new ImagePolicy(maxBytes: $maxBytes);
})->throws(InvalidArgumentException::class, 'maxBytes must be at least 1.')->with([0, -1]);

test('ImagePolicy accepts a one-byte limit', function () {
    expect((new ImagePolicy(maxBytes: 1))->maxBytes)->toBe(1);
});
