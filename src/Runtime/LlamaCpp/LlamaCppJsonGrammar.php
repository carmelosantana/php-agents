<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime\LlamaCpp;

/**
 * @deprecated 0.18 Use \CarmeloSantana\PHPAgents\Provider\LlamaServerProvider (llama.cpp's llama-server).
 *             The FFI binding matches llama.h only up to llama.cpp b9174 (newer builds segfault)
 *             and, with tools (always under Agent), buffers the whole generation. Removed or
 *             fixed in 0.19.
 */
final class LlamaCppJsonGrammar
{
    public const string ROOT_RULE = 'root';

    public const string GRAMMAR = <<<'GBNF'
root   ::= object
value  ::= object | array | string | number | ("true" | "false" | "null") ws

object ::=
  "{" ws (
            string ":" ws value
    ("," ws string ":" ws value)*
  )? "}" ws

array  ::=
  "[" ws (
            value
    ("," ws value)*
  )? "]" ws

string ::=
  "\"" (
    [^"\\\x7F\x00-\x1F] |
    "\\" (["\\bfnrt] | "u" [0-9a-fA-F]{4})
  )* "\"" ws

number ::= ("-"? ([0-9] | [1-9] [0-9]{0,15})) ("." [0-9]+)? ([eE] [-+]? [0-9] [1-9]{0,15})? ws

ws ::= | " " | "\n" [ \t]{0,20}
GBNF;

    private function __construct() {}
}