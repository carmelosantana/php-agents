<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Tool\SchemaTool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Gemini's Schema has `anyOf` and no field for the keywords below, so the tool path and
// structured() strip them from the root, a `properties` member, `items` and an `anyOf` branch
// (Kanboard subtask 6482, widened to `oneOf` and `allOf` on Kanboard #4437 comment 1382).

/**
 * Format one raw schema as a Gemini tool and return its `parameters`, JSON-encoded.
 *
 * @param array<string, mixed> $schema
 */
function geminiParametersJson(array $schema): string
{
    $provider = new GeminiProvider(httpClient: new MockHttpClient());
    $tool = new SchemaTool('raw_tool', 'Raw.', $schema, fn(array $a): ToolResult => ToolResult::success('x'));
    // formatTools() is protected; ReflectionMethod::invoke() reaches it without setAccessible() on PHP 8.1+.
    $formatted = (new ReflectionMethod($provider, 'formatTools'))->invoke($provider, [$tool]);

    return json_encode($formatted[0]['functionDeclarations'][0]['parameters'], JSON_THROW_ON_ERROR);
}

/** @return array<string, array{string, mixed}> */
function geminiStrippedKeywords(): array
{
    return [
        'contains' => ['contains', ['type' => 'string']],
        'not' => ['not', ['type' => 'integer']],
        'if' => ['if', ['type' => 'string']],
        'then' => ['then', ['type' => 'string']],
        'else' => ['else', ['type' => 'number']],
        'propertyNames' => ['propertyNames', ['type' => 'string', 'pattern' => '^a']],
        'prefixItems' => ['prefixItems', [['type' => 'string']]],
        'dependentSchemas' => ['dependentSchemas', ['x' => ['type' => 'object']]],
        'dependentRequired' => ['dependentRequired', ['x' => ['y']]],
        'oneOf' => ['oneOf', [['type' => 'string'], ['type' => 'integer']]],
        'allOf' => ['allOf', [['type' => 'string']]],
    ];
}

/**
 * Where the node under test sits: a wrapper that places it, and the JSON the wrapper renders
 * as once the node has become `{"type":"OBJECT"}`.
 *
 * @return array<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}>
 */
function geminiStripPositions(): array
{
    return [
        'root' => [
            static fn(array $node): array => $node,
            '{"type":"OBJECT"}',
        ],
        'properties member' => [
            static fn(array $node): array => ['type' => 'object', 'properties' => ['x' => $node]],
            '{"type":"OBJECT","properties":{"x":{"type":"OBJECT"}}}',
        ],
        'items' => [
            static fn(array $node): array => ['type' => 'object', 'properties' => ['list' => ['type' => 'array', 'items' => $node]]],
            '{"type":"OBJECT","properties":{"list":{"type":"ARRAY","items":{"type":"OBJECT"}}}}',
        ],
        'anyOf branch' => [
            static fn(array $node): array => ['type' => 'object', 'properties' => ['x' => ['anyOf' => [$node, ['type' => 'null']]]]],
            '{"type":"OBJECT","properties":{"x":{"anyOf":[{"type":"OBJECT"},{"type":"NULL"}]}}}',
        ],
    ];
}

test('gemini strips a keyword its Schema has no field for from the node it sits on', function (string $keyword, mixed $value, string $position) {
    [$place, $expected] = geminiStripPositions()[$position];

    expect(geminiParametersJson($place(['type' => 'object', $keyword => $value])))->toBe($expected);
})->with(geminiStrippedKeywords())->with(array_combine(
    array_keys(geminiStripPositions()),
    array_map(static fn(string $p): array => [$p], array_keys(geminiStripPositions())),
));

test('a property named like a stripped keyword keeps its name and is normalised', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'not' => ['type' => 'string'],
            'oneOf' => ['type' => 'integer'],
            'if' => ['type' => 'boolean', 'not' => ['const' => true]],
            'allOf' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['if', 'not'],
    ];

    expect(geminiParametersJson($schema))->toBe(
        '{"type":"OBJECT","properties":{"not":{"type":"STRING"},"oneOf":{"type":"INTEGER"},"if":{"type":"BOOLEAN"},"allOf":{"type":"ARRAY","items":{"type":"STRING"}}},"required":["if","not"]}',
    );
});

test('gemini keeps anyOf at depth and normalises the nodes in its branches', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'outer' => [
                'type' => 'object',
                'properties' => [
                    'pick' => ['anyOf' => [
                        ['type' => 'string', 'not' => ['type' => 'integer']],
                        ['type' => 'object', 'properties' => ['z' => ['type' => ['integer', 'null']]]],
                    ]],
                ],
            ],
        ],
    ];

    expect(geminiParametersJson($schema))->toBe(
        '{"type":"OBJECT","properties":{"outer":{"type":"OBJECT","properties":{"pick":{"anyOf":[{"type":"STRING"},{"type":"OBJECT","properties":{"z":{"type":"INTEGER","nullable":true}}}]}}}}}',
    );
});

test('a node built only of stripped keywords becomes {} at a property, under items and in an anyOf branch', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'target' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            'list' => ['type' => 'array', 'items' => ['allOf' => [['type' => 'string']]]],
            'pick' => ['anyOf' => [['if' => ['type' => 'string'], 'then' => ['minLength' => 1]], ['type' => 'null']]],
        ],
    ];

    expect(geminiParametersJson($schema))->toBe(
        '{"type":"OBJECT","properties":{"target":{},"list":{"type":"ARRAY","items":{}},"pick":{"anyOf":[{},{"type":"NULL"}]}}}',
    );
});

test('structured strips the same keywords from responseSchema', function () {
    $payload = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$payload): MockResponse {
        $payload = json_decode((string) $options['body'], true);

        return new MockResponse(
            (string) json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => '{}']]], 'finishReason' => 'STOP']]]),
            ['http_code' => 200],
        );
    });
    $provider = new GeminiProvider(model: 'gemini-2.5-flash', apiKey: 'test-key', httpClient: $client);

    $provider->structured([new UserMessage('hi')], (string) json_encode([
        'type' => 'object',
        'properties' => ['a' => ['type' => 'string', 'not' => ['const' => '']]],
        'if' => ['required' => ['a']],
        'then' => ['properties' => ['a' => ['minLength' => 1]]],
        'oneOf' => [['required' => ['a']]],
    ]));

    expect($payload['generationConfig']['responseSchema'])->toBe([
        'type' => 'OBJECT',
        'properties' => ['a' => ['type' => 'STRING']],
    ]);
});
