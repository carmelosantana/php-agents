<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\DonePreviewReader;

/**
 * Feed $json to a fresh reader in pieces of $size bytes; return each push's result.
 *
 * @return list<string>
 */
function donePreviewPushes(string $json, int $size = 1): array
{
    $reader = new DonePreviewReader();

    return array_map(fn(string $piece): string => $reader->push($piece), str_split($json, $size));
}

test('a response fed one byte at a time decodes in total', function () {
    $json = '{"response":"Hi\nthere"}';
    $reader = new DonePreviewReader();
    $pushed = '';
    foreach (str_split($json) as $byte) {
        $pushed .= $reader->push($byte);
    }

    expect($pushed)->toBe("Hi\nthere")
        ->and($reader->text())->toBe("Hi\nthere");
});

test('escapes decode wherever the fragments split them', function () {
    $answer = "say \"hi\" \\ a/b\tend 😀 é";
    $json = (string) json_encode(['response' => $answer]);

    expect($json)->toContain('\ud83d\ude00')
        ->and($json)->toContain('\/');

    for ($cut = 1; $cut < strlen($json); $cut++) {
        $reader = new DonePreviewReader();
        $pushed = $reader->push(substr($json, 0, $cut)) . $reader->push(substr($json, $cut));

        expect($pushed)->toBe($answer, "cut at {$cut}")
            ->and($reader->text())->toBe($answer);
    }
});

test('a surrogate pair is emitted as one emoji only once it is complete', function () {
    $pushes = donePreviewPushes('{"response":"\ud83d\ude00"}');

    expect(implode('', $pushes))->toBe('😀')
        ->and(array_values(array_filter($pushes, fn(string $p): bool => $p !== '')))->toBe(['😀']);
});

test('a raw multibyte character split across pushes is emitted whole', function () {
    $json = '{"response":"é"}';
    $split = strpos($json, "\xC3") + 1;
    $reader = new DonePreviewReader();

    expect($reader->push(substr($json, 0, $split)))->toBe('')
        ->and($reader->push(substr($json, $split)))->toBe('é')
        ->and($reader->text())->toBe('é');
});

test('a raw four-byte character fed byte by byte is emitted in one push', function () {
    $pushes = donePreviewPushes('{"response":"a😀b"}');

    expect(array_values(array_filter($pushes, fn(string $p): bool => $p !== '')))->toBe(['a', '😀', 'b']);
});

test('only the top-level response key is read', function () {
    $pushes = donePreviewPushes('{"other":{"response":"x"},"list":["response"],"response":"y"}');

    expect(implode('', $pushes))->toBe('y');
});

test('whitespace around the colon is allowed', function () {
    $reader = new DonePreviewReader();
    $reader->push("{ \"response\" \n:\t \"ok\" }");

    expect($reader->text())->toBe('ok');
});

test('a key with an escape matches when it decodes to response', function () {
    $reader = new DonePreviewReader();
    $reader->push('{"respons\u0065":"ok"}');

    expect($reader->text())->toBe('ok');
});

test('no response key yields no text', function () {
    $reader = new DonePreviewReader();

    expect($reader->push('{"answer":"nope","n":1}'))->toBe('')
        ->and($reader->text())->toBe('');
});

test('a non-string response yields no text', function () {
    $reader = new DonePreviewReader();
    $reader->push('{"response":{"text":"x"}}');

    expect($reader->text())->toBe('');
});

test('a lone surrogate decodes as the replacement character', function () {
    $reader = new DonePreviewReader();
    $reader->push('{"response":"a\ud83db\ude00c"}');

    expect($reader->text())->toBe("a\u{FFFD}b\u{FFFD}c");
});

test('text after the response string ends is ignored', function () {
    $reader = new DonePreviewReader();
    $reader->push('{"response":"first","response":"second"}');

    expect($reader->text())->toBe('first');
});

test('unicode escapes decode at every UTF-8 length boundary', function () {
    $reader = new DonePreviewReader();
    $reader->push('{"response":"\u0030\u007f\u0080\u07ff\u0800\uffff\ud800\udc00\udbff\udfff"}');

    expect($reader->text())->toBe("0\u{7F}\u{80}\u{7FF}\u{800}\u{FFFF}\u{10000}\u{10FFFF}");
});

test('a lone high surrogate at the end of the response decodes as the replacement character', function () {
    $reader = new DonePreviewReader();
    $reader->push('{"response":"a\udbff"}');

    expect($reader->text())->toBe("a\u{FFFD}");
});
