<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\VectorStore\InMemoryVectorStore;
use CarmeloSantana\PHPAgents\Memory\Document;
use CarmeloSantana\PHPAgents\Exception\DocumentException;

test('addDocument throws when document has no embedding', function () {
    $store = new InMemoryVectorStore();
    $doc = new Document(content: 'test');

    $store->addDocument($doc);
})->throws(DocumentException::class);

test('addDocument succeeds with embedding', function () {
    $store = new InMemoryVectorStore();
    $doc = new Document(content: 'test');
    $doc->setEmbedding([0.1, 0.2, 0.3]);

    $store->addDocument($doc);

    $results = $store->similaritySearch([0.1, 0.2, 0.3], 10);
    expect($results)->toHaveCount(1);
});

test('similaritySearch returns documents in order of relevance', function () {
    $store = new InMemoryVectorStore();

    $doc1 = new Document(content: 'first', id: 'd1');
    $doc1->setEmbedding([1.0, 0.0, 0.0]);
    $store->addDocument($doc1);

    $doc2 = new Document(content: 'second', id: 'd2');
    $doc2->setEmbedding([0.9, 0.1, 0.0]);
    $store->addDocument($doc2);

    $doc3 = new Document(content: 'third', id: 'd3');
    $doc3->setEmbedding([0.0, 0.0, 1.0]);
    $store->addDocument($doc3);

    $results = $store->similaritySearch([1.0, 0.0, 0.0], 3);

    expect($results)->toHaveCount(3);
    expect($results[0]->content)->toBe('first');
    expect($results[1]->content)->toBe('second');
});

test('similaritySearch respects limit', function () {
    $store = new InMemoryVectorStore();

    for ($i = 0; $i < 5; $i++) {
        $doc = new Document(content: "doc-{$i}", id: "d-{$i}");
        $doc->setEmbedding([0.5, 0.5, (float) $i / 10]);
        $store->addDocument($doc);
    }

    $results = $store->similaritySearch([0.5, 0.5, 0.0], 2);

    expect($results)->toHaveCount(2);
});

test('deleteByIds removes specific documents', function () {
    $store = new InMemoryVectorStore();

    $doc1 = new Document(content: 'keep', id: 'keep');
    $doc1->setEmbedding([1.0, 0.0]);
    $store->addDocument($doc1);

    $doc2 = new Document(content: 'remove', id: 'remove');
    $doc2->setEmbedding([0.0, 1.0]);
    $store->addDocument($doc2);

    $store->deleteByIds(['remove']);

    $results = $store->similaritySearch([0.0, 1.0], 10);
    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe('keep');
});
