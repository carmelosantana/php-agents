<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Memory\Document;

test('document stores content and metadata', function () {
    $doc = new Document(
        content: 'Test content',
        sourceType: 'file',
        sourceName: 'test.txt',
        metadata: ['key' => 'value'],
        id: 'doc-1',
    );

    expect($doc->content)->toBe('Test content');
    expect($doc->sourceType)->toBe('file');
    expect($doc->sourceName)->toBe('test.txt');
    expect($doc->metadata)->toBe(['key' => 'value']);
    expect($doc->id)->toBe('doc-1');
});

test('document has no embedding by default', function () {
    $doc = new Document(content: 'test');

    expect($doc->hasEmbedding())->toBeFalse();
    expect($doc->getEmbedding())->toBeNull();
});

test('withEmbedding returns new instance', function () {
    $doc = new Document(content: 'test');
    $embedding = [0.1, 0.2, 0.3];

    $withEmbed = $doc->withEmbedding($embedding);

    expect($withEmbed)->not->toBe($doc);
    expect($withEmbed->hasEmbedding())->toBeTrue();
    expect($withEmbed->getEmbedding())->toBe($embedding);
    expect($doc->hasEmbedding())->toBeFalse();
});

test('setEmbedding mutates in place', function () {
    $doc = new Document(content: 'test');
    $doc->setEmbedding([0.1, 0.2]);

    expect($doc->hasEmbedding())->toBeTrue();
    expect($doc->getEmbedding())->toBe([0.1, 0.2]);
});

test('document defaults', function () {
    $doc = new Document(content: 'hello');

    expect($doc->sourceType)->toBe('memory');
    expect($doc->sourceName)->toBe('');
    expect($doc->metadata)->toBe([]);
    expect($doc->id)->toBeNull();
});
