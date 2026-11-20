<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Agent;

/**
 * Reads a `done` call's argument JSON as it streams and decodes the top-level `response`
 * string. Fragments may split the JSON anywhere, including inside an escape or a multibyte
 * character; text is released only once each character is complete. Keys of nested objects
 * and arrays are ignored. Reading stops at the end of the first top-level `response` string;
 * a lone UTF-16 surrogate decodes as U+FFFD.
 */
final class DonePreviewReader
{
    private const SCAN = 0;
    private const SKIP_STRING = 1;
    private const SKIP_ESCAPE = 2;
    private const KEY = 3;
    private const KEY_ESCAPE = 4;
    private const AFTER_KEY = 5;
    private const BEFORE_VALUE = 6;
    private const TARGET = 7;
    private const TARGET_ESCAPE = 8;
    private const TARGET_UNICODE = 9;
    private const FINISHED = 10;

    private const REPLACEMENT = "\u{FFFD}";

    private const ESCAPES = ['n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 'f' => "\x0C"];

    private int $state = self::SCAN;

    /** Nesting depth of objects and arrays; the top-level object is depth 1. */
    private int $depth = 0;

    /** Whether the next string at depth 1 is a key. */
    private bool $expectKey = false;

    /** Raw bytes of the key being read. */
    private string $key = '';

    /** Whether the key just read decodes to `response`. */
    private bool $isTarget = false;

    private string $hex = '';

    private ?int $highSurrogate = null;

    /** Decoded bytes not yet released. */
    private string $pending = '';

    private string $text = '';

    /**
     * Read the next fragment and return the text it completed (possibly '').
     */
    public function push(string $jsonFragment): string
    {
        $length = strlen($jsonFragment);
        for ($i = 0; $i < $length && $this->state !== self::FINISHED; $i++) {
            $this->read($jsonFragment[$i]);
        }

        $cut = $this->state === self::FINISHED ? strlen($this->pending) : $this->completeLength($this->pending);
        $released = substr($this->pending, 0, $cut);
        $this->pending = substr($this->pending, $cut);
        $this->text .= $released;

        return $released;
    }

    /**
     * All text released so far.
     */
    public function text(): string
    {
        return $this->text;
    }

    private function read(string $c): void
    {
        switch ($this->state) {
            case self::SCAN:
                $this->scan($c);
                break;
            case self::SKIP_STRING:
                $this->state = match ($c) {
                    '\\' => self::SKIP_ESCAPE,
                    '"' => self::SCAN,
                    default => self::SKIP_STRING,
                };
                break;
            case self::SKIP_ESCAPE:
                $this->state = self::SKIP_STRING;
                break;
            case self::KEY:
                if ($c === '"') {
                    $this->isTarget = json_decode('"' . $this->key . '"') === 'response';
                    $this->expectKey = false;
                    $this->state = self::AFTER_KEY;
                } else {
                    $this->key .= $c;
                    if ($c === '\\') {
                        $this->state = self::KEY_ESCAPE;
                    }
                }
                break;
            case self::KEY_ESCAPE:
                $this->key .= $c;
                $this->state = self::KEY;
                break;
            case self::AFTER_KEY:
                if ($c === ':') {
                    $this->state = self::BEFORE_VALUE;
                } elseif (!ctype_space($c)) {
                    $this->state = self::SCAN;
                    $this->scan($c);
                }
                break;
            case self::BEFORE_VALUE:
                if (ctype_space($c)) {
                    break;
                }
                if ($c === '"' && $this->isTarget) {
                    $this->state = self::TARGET;
                    break;
                }
                $this->state = self::SCAN;
                $this->scan($c);
                break;
            case self::TARGET:
                if ($c !== '\\') {
                    $this->flushHighSurrogate();
                }
                if ($c === '"') {
                    $this->state = self::FINISHED;
                } elseif ($c === '\\') {
                    $this->state = self::TARGET_ESCAPE;
                } else {
                    $this->pending .= $c;
                }
                break;
            case self::TARGET_ESCAPE:
                if ($c === 'u') {
                    $this->hex = '';
                    $this->state = self::TARGET_UNICODE;
                    break;
                }
                $this->flushHighSurrogate();
                $this->pending .= self::ESCAPES[$c] ?? $c;
                $this->state = self::TARGET;
                break;
            case self::TARGET_UNICODE:
                $this->hex .= $c;
                if (strlen($this->hex) === 4) {
                    $this->unicode($this->hex);
                    $this->state = self::TARGET;
                }
                break;
        }
    }

    /**
     * Track structure outside strings: depth, where depth-1 keys start, and skipped strings.
     */
    private function scan(string $c): void
    {
        switch ($c) {
            case '{':
                $this->depth++;
                $this->expectKey = $this->depth === 1;
                break;
            case '[':
                $this->depth++;
                break;
            case '}':
            case ']':
                $this->depth--;
                break;
            case ',':
                $this->expectKey = $this->depth === 1;
                break;
            case '"':
                if ($this->depth === 1 && $this->expectKey) {
                    $this->key = '';
                    $this->state = self::KEY;
                } else {
                    $this->state = self::SKIP_STRING;
                }
                break;
        }
    }

    private function unicode(string $hex): void
    {
        if (!ctype_xdigit($hex)) {
            $this->flushHighSurrogate();
            $this->pending .= self::REPLACEMENT;

            return;
        }

        $code = (int) hexdec($hex);
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            $this->flushHighSurrogate();
            $this->highSurrogate = $code;
        } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
            if ($this->highSurrogate === null) {
                $this->pending .= self::REPLACEMENT;
            } else {
                $this->pending .= self::utf8(0x10000 + (($this->highSurrogate - 0xD800) << 10) + ($code - 0xDC00));
                $this->highSurrogate = null;
            }
        } else {
            $this->flushHighSurrogate();
            $this->pending .= self::utf8($code);
        }
    }

    /**
     * A high surrogate not followed by a low one decodes as U+FFFD.
     */
    private function flushHighSurrogate(): void
    {
        if ($this->highSurrogate !== null) {
            $this->pending .= self::REPLACEMENT;
            $this->highSurrogate = null;
        }
    }

    /**
     * Length of the longest prefix of $bytes that does not end inside a UTF-8 sequence.
     */
    private function completeLength(string $bytes): int
    {
        $length = strlen($bytes);
        for ($i = $length - 1; $i >= max(0, $length - 4); $i--) {
            $byte = ord($bytes[$i]);
            if (($byte & 0xC0) === 0x80) {
                continue;
            }

            $need = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $length - $i < $need ? $i : $length;
        }

        return $length;
    }

    /**
     * UTF-8 for a code point; one mb_chr() cannot encode (a surrogate, or out of range) is U+FFFD.
     */
    private static function utf8(int $code): string
    {
        $char = mb_chr($code, 'UTF-8');

        return $char === false ? self::REPLACEMENT : $char;
    }
}
