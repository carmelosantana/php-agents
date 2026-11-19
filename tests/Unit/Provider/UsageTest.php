<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\Usage;

it('keeps positional construction with three ints', function () {
    $usage = new Usage(10, 5, 15);

    expect([$usage->promptTokens, $usage->completionTokens, $usage->totalTokens])->toBe([10, 5, 15])
        ->and($usage->cacheReadTokens)->toBeNull()
        ->and($usage->cacheWriteTokens)->toBeNull()
        ->and($usage->reasoningTokens)->toBeNull();
});

it('sums usage including nullable fields', function () {
    $sum = (new Usage(10, 5, 15, cacheReadTokens: 4))->plus(new Usage(1, 1, 2));

    expect([
        $sum->promptTokens,
        $sum->completionTokens,
        $sum->totalTokens,
        $sum->cacheReadTokens,
        $sum->cacheWriteTokens,
        $sum->reasoningTokens,
    ])->toBe([11, 6, 17, 4, null, null]);
});

it('keeps null when both sides are null', function () {
    expect((new Usage())->plus(new Usage())->reasoningTokens)->toBeNull();
});

it('treats null as zero when the other side reported zero', function () {
    $sum = (new Usage(cacheWriteTokens: 0))->plus(new Usage());

    expect($sum->cacheWriteTokens)->toBe(0)
        ->and((new Usage())->plus(new Usage(reasoningTokens: 0))->reasoningTokens)->toBe(0);
});

it('adds reported nullable fields on both sides', function () {
    $sum = (new Usage(reasoningTokens: 3, cacheWriteTokens: 2))
        ->plus(new Usage(reasoningTokens: 4, cacheWriteTokens: 5));

    expect($sum->reasoningTokens)->toBe(7)
        ->and($sum->cacheWriteTokens)->toBe(7);
});
