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
