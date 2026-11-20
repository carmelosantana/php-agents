<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Security\UntrustedText;

// Error text from providers, tools and MCP servers reaches users, events and the model. These pin
// the secret patterns redactSecrets() removes and the cleaning scrub() applies.

dataset('secrets', [
    'bearer' => ['Authorization failed for Bearer abcdefgh12345678', 'Authorization failed for Bearer [redacted]'],
    'openai' => ['bad key sk-AAAAAAAAAAAAAAAAAAAA here', 'bad key [redacted] here'],
    'anthropic' => ['bad key sk-ant-api03-AAAAAAAAAAAAAAAAAAAA here', 'bad key [redacted] here'],
    'google' => ['key AIza' . str_repeat('B', 35) . ' denied', 'key [redacted] denied'],
    'xai' => ['key xai-' . str_repeat('C', 20) . ' denied', 'key [redacted] denied'],
    'github' => ['token ghp_' . str_repeat('D', 36) . ' denied', 'token [redacted] denied'],
    'aws' => ['id AKIAABCDEFGHIJKLMNOP denied', 'id [redacted] denied'],
    'api_key=' => ['url ?api_key=s3cr3t&x=1', 'url ?api_key=[redacted]&x=1'],
    'x-api-key:' => ['x-api-key: s3cr3t', 'x-api-key: [redacted]'],
    'x-goog-api-key' => ['X-Goog-Api-Key: s3cr3t', 'X-Goog-Api-Key: [redacted]'],
    'json password' => ['{"password": "hunter2", "user": "a"}', '{"password": "[redacted]", "user": "a"}'],
    'token=' => ['token=abc123', 'token=[redacted]'],
    'secret:' => ["secret: 'xyz'", "secret: '[redacted]'"],
    'authorization basic' => ['authorization=Basic', 'authorization=[redacted]'],
]);

test('redactSecrets removes each credential shape', function (string $in, string $out) {
    expect(UntrustedText::redactSecrets($in))->toBe($out);
})->with('secrets');

test('a plain message is unchanged', function () {
    $message = 'File not found: /tmp/report.txt (tried 3 times)';

    expect(UntrustedText::redactSecrets($message))->toBe($message)
        ->and(UntrustedText::forDisplay($message))->toBe($message);
});

test('scrub turns control and format characters into spaces and collapses whitespace', function () {
    expect(UntrustedText::scrub("Bad\x1b[2J\r\n  x\u{202E}y\u{200B}z", 300))->toBe('Bad [2J x y z');
});

test('scrub cuts to the limit with an ellipsis', function () {
    $out = UntrustedText::scrub(str_repeat('x', 1000), 300);

    expect($out)->toBe(str_repeat('x', 300) . '…')
        ->and(UntrustedText::scrub(str_repeat('x', 300), 300))->toBe(str_repeat('x', 300));
});

test('scrub repairs invalid UTF-8', function () {
    expect(mb_check_encoding(UntrustedText::scrub("ok \xff\xfe end", 300), 'UTF-8'))->toBeTrue();
});

test('forDisplay redacts before it cuts, so a secret on the boundary is not half-shown', function () {
    $text = str_repeat('a', 280) . ' sk-ant-' . str_repeat('Z', 40);
    $out = UntrustedText::forDisplay($text, 300);

    expect($out)->not->toContain('sk-ant')
        ->and($out)->not->toContain('ZZZ')
        ->and($out)->toContain('[redacted]')
        ->and(mb_strlen($out))->toBeLessThanOrEqual(301);
});

test('forDisplay defaults to 500 characters', function () {
    expect(mb_strlen(UntrustedText::forDisplay(str_repeat('y', 2000))))->toBe(501);
});

// Each row: the text, then a fragment of its secret that must not survive (null: nothing to hide).
dataset('whitespace_runs', [
    'key then spaces' => ['token' . str_repeat(' ', 1024 * 1024) . 'x', null],
    'key, spaces, quote' => ['password' . str_repeat(' ', 1024 * 1024) . '"', null],
    'bearer then spaces' => ['Bearer' . str_repeat(' ', 1024 * 1024) . '!', null],
    'basic then spaces' => ['Basic' . str_repeat(' ', 1024 * 1024) . '!', null],
    'unclosed quoted value' => ['password: "' . str_repeat('a', 1024 * 1024), str_repeat('a', 64)],
    'unclosed quoted escapes' => ['password: "' . str_repeat('\\"', 512 * 1024), str_repeat('\\"', 8)],
    'unclosed single-quoted escapes' => ["secret: '" . str_repeat("\\'", 512 * 1024), str_repeat("\\'", 8)],
    'lowercase bearer then spaces' => ['bearer' . str_repeat(' ', 1024 * 1024) . '!', null],
]);

// Without the PCRE JIT a long escape run can hit pcre.backtrack_limit; redactSecrets() then
// fails closed to `[redacted]`. Either outcome is fine as long as it is fast and leaks nothing.
test('redactSecrets stays linear on a 1 MiB whitespace run', function (string $text, ?string $secret) {
    $start = microtime(true);
    $out = UntrustedText::redactSecrets($text);
    $elapsed = microtime(true) - $start;

    expect(preg_last_error() === PREG_NO_ERROR || $out === '[redacted]')->toBeTrue()
        ->and($elapsed)->toBeLessThan(0.25);
    if ($secret !== null) {
        expect($out)->not->toContain($secret);
    }
})->with('whitespace_runs');

test('a Basic credential is redacted and the scheme kept', function () {
    expect(UntrustedText::redactSecrets('echo Authorization: Basic dXNlcjpwYXNzd29yZA== done'))->not->toContain('dXNlcjpwYXNz')
        ->and(UntrustedText::redactSecrets('sent Basic dXNlcjpwYXNzd29yZA== done'))->toBe('sent Basic [redacted] done')
        ->and(UntrustedText::redactSecrets('basic configuration is missing'))->toBe('basic configuration is missing');
});

test('a quoted credential value is redacted whole', function () {
    expect(UntrustedText::redactSecrets('{"password": "correct horse battery", "user": "a"}'))
        ->toBe('{"password": "[redacted]", "user": "a"}')
        ->and(UntrustedText::redactSecrets("secret='two words'"))->toBe("secret='[redacted]'")
        ->and(UntrustedText::redactSecrets('password: "cut off here'))->toBe('password: "[redacted]');
});

test('the Bearer and Basic schemes match in any case', function () {
    expect(UntrustedText::redactSecrets('Authorization: bearer opaque-CREDENTIAL-123456'))->not->toContain('CREDENTIAL')
        ->and(UntrustedText::redactSecrets('sent bearer opaque-CREDENTIAL-123456 done'))->toBe('sent bearer [redacted] done')
        ->and(UntrustedText::redactSecrets('sent BEARER opaque-CREDENTIAL-123456 done'))->toBe('sent BEARER [redacted] done')
        ->and(UntrustedText::redactSecrets('sent basic dXNlcjpwYXNzd29yZA== done'))->toBe('sent basic [redacted] done')
        ->and(UntrustedText::redactSecrets('Basic configuration is missing'))->toBe('Basic configuration is missing');
});

test('a quoted value with an escaped quote is redacted whole', function () {
    expect(UntrustedText::redactSecrets('{"password": "pa\\"ss word", "user": "a"}'))
        ->toBe('{"password": "[redacted]", "user": "a"}')
        ->and(UntrustedText::redactSecrets("secret='it\\'s mine' ok"))->toBe("secret='[redacted]' ok")
        ->and(UntrustedText::redactSecrets('token: "a\\\\" tail'))->toBe('token: "[redacted]" tail');
});

test('an unclosed quoted value is redacted to the end of the text', function () {
    expect(UntrustedText::redactSecrets('{"password": "pa\\"ss word leaks'))->toBe('{"password": "[redacted]')
        ->and(UntrustedText::redactSecrets("secret='never closed"))->toBe("secret='[redacted]");
});

// A hostile sender can split a credential with a control or format character that scrub()
// later turns into a space; forDisplay() must still redact it.
dataset('split_secrets', [
    'bidi between Bearer and its token' => ["denied: Bearer\u{202E}abcdefghijkl", 'abcdefghijkl'],
    'ESC between Bearer and its token' => ["denied: Bearer\eabcdefghijkl", 'abcdefghijkl'],
    'zero-width space inside an sk- key' => ["bad key sk-AAAAAAAAAAAAAAA\u{200B}BBBBBBBBB here", 'AAAAAAAAAAAAAAA'],
    'ESC inside an sk- key' => ["bad key sk-AAAAAAAA\eBBBBBBBBBBBB here", 'BBBBBBBBBBBB'],
    'bidi isolate inside a GitHub token' => ["token ghp_AAAAAAAAAA\u{2066}BBBBBBBBBBBBBBBBBBBB", 'BBBBBBBBBBBBBBBBBBBB'],
    'C1 CSI inside an AWS key id' => ["id AKIAABCDEFGH\u{009B}IJKLMNOP denied", 'IJKLMNOP'],
]);

test('forDisplay redacts a credential split by a control or format character', function (string $in, string $fragment) {
    $out = UntrustedText::forDisplay($in, 300);

    expect($out)->not->toContain($fragment)
        ->and($out)->toContain('[redacted]')
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', $out))->toBe(0);
})->with('split_secrets');

test('forDisplay still turns control characters into spaces when nothing is split', function () {
    expect(UntrustedText::forDisplay("stop\e[31m red\u{202E}x", 300))->toBe('stop [31m red x');
});

dataset('glued_secrets', [
    'sk- after an underscore' => ['reason_sk-' . str_repeat('A1b2', 6), 'reason_[redacted]'],
    'AIza after an underscore' => ['x_AIza' . str_repeat('B', 35), 'x_[redacted]'],
    'xai- after an underscore' => ['model_xai-' . str_repeat('C', 20), 'model_[redacted]'],
    'GitHub token after an underscore' => ['id_ghp_' . str_repeat('D', 36), 'id_[redacted]'],
    'AWS key id after an underscore' => ['key_AKIAABCDEFGHIJKLMNOP', 'key_[redacted]'],
]);

test('redactSecrets catches a key glued to a word character', function (string $in, string $out) {
    expect(UntrustedText::redactSecrets($in))->toBe($out);
})->with('glued_secrets');

test('words that end in sk or contain gh_ are not keys', function () {
    foreach ([
        'task-abcdefghijklmnopqrstuvwxyz failed',
        'risk-assessment-for-quarterly-review',
        'desk-reservation-system-v2-beta-build',
        'highAIzaconfiguration-is-not-a-google-key-at-all',
        'laugh_abcdefghijklmnopqrstuvwxyz',
    ] as $text) {
        expect(UntrustedText::redactSecrets($text))->toBe($text);
    }
});

test('scrub turns invisible Hangul fillers into spaces', function () {
    expect(UntrustedText::scrub("a\u{3164}b\u{115F}c\u{1160}d\u{FFA0}e", 300))->toBe('a b c d e');
});

test('scrub does not separate a base letter from its combining mark at the cut', function () {
    expect(UntrustedText::scrub(str_repeat('x', 9) . "e\u{0301}zz", 10))->toBe(str_repeat('x', 9) . '…')
        ->and(UntrustedText::scrub(str_repeat('x', 8) . "e\u{0301}zz", 10))->toBe(str_repeat('x', 8) . "e\u{0301}…");
});
