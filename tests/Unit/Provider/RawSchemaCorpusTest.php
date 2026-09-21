<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Provider\AnthropicProvider;
use CarmeloSantana\PHPAgents\Provider\GeminiProvider;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppToolSchemaNormalizer;
use CarmeloSantana\PHPAgents\Provider\MistralProvider;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAIResponsesProvider;
use CarmeloSantana\PHPAgents\Provider\XAIProvider;
use CarmeloSantana\PHPAgents\Tool\SchemaTool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;

// Every MCP-style schema in tests/Fixtures/mcp-schemas goes through every provider's tool
// formatting. It must not throw, and wherever JSON Schema needs an object (a map or a
// schema), the encoded payload must carry an object, never a list, since `[]` there is what
// providers reject.
//
// Which providers "every" names is not left to this comment: the last test in this file
// reads the concrete providers off disk and fails when one is neither exercised here nor
// listed there as sending no tool schema.

/** @return array<string, array{string}> */
function rawSchemaFixtures(): array
{
    $cases = [];
    foreach (glob(__DIR__ . '/../../Fixtures/mcp-schemas/*.json') ?: [] as $file) {
        $cases[basename($file, '.json')] = [$file];
    }
    ksort($cases);

    return $cases;
}

/** @return array<string, \Closure(list<SchemaTool>): mixed> */
function rawSchemaFormatters(): array
{
    $http = new MockHttpClient();
    // formatTools() is protected; ReflectionMethod::invoke() reaches it without setAccessible() on PHP 8.1+.
    $via = static fn(object $provider): \Closure => static fn(array $tools): mixed => (new ReflectionMethod($provider, 'formatTools'))->invoke($provider, $tools);

    return [
        'openai-chat' => $via(new OpenAICompatibleProvider(model: 'm', httpClient: $http)),
        'openai-responses' => $via(new OpenAIResponsesProvider(model: 'm', httpClient: $http)),
        'mistral' => $via(new MistralProvider(httpClient: $http)),
        'xai' => $via(new XAIProvider(model: 'm', httpClient: $http)),
        'anthropic' => $via(new AnthropicProvider(httpClient: $http)),
        'ollama' => $via(new OllamaProvider(httpClient: $http)),
        'gemini' => $via(new GeminiProvider(httpClient: $http)),
        'llama-cpp' => static fn(array $tools): mixed => array_map(
            static fn($d): array => ['parameters' => $d->parameters],
            (new LlamaCppToolSchemaNormalizer())->normalize($tools),
        ),
    ];
}

/**
 * Walk a json_decode(..., false) tree; report every map/schema position that decoded as a list.
 *
 * A position is a map (`properties` and friends) or a subschema. Both must encode as a JSON
 * object, so an array at either is a defect — including a *member* of a map, which is where a
 * node that a provider stripped down to nothing ends up. The keyword lists come from the JSON
 * Schema 2020-12 vocabulary, not from any list a provider keeps, so a keyword a provider
 * forgot is still checked here.
 */
function listsWhereObjectsBelong(mixed $node, string $path = '$'): array
{
    $maps = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];
    $subschemas = ['additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'contentSchema', 'additionalItems'];
    $subschemaLists = ['anyOf', 'oneOf', 'allOf', 'prefixItems'];

    $bad = [];
    if ($node instanceof stdClass) {
        foreach (get_object_vars($node) as $key => $value) {
            $here = "{$path}.{$key}";
            if (in_array($key, $maps, true)) {
                if (is_array($value)) {
                    $bad[] = $here;
                }
                foreach ((array) $value as $name => $member) {
                    if (is_array($member)) {
                        $bad[] = "{$here}.{$name}";
                    }
                }
            } elseif (in_array($key, $subschemas, true) && is_array($value)) {
                $bad[] = $here;
            } elseif (in_array($key, $subschemaLists, true) && is_array($value)) {
                foreach ($value as $index => $member) {
                    if (is_array($member)) {
                        $bad[] = "{$here}[{$index}]";
                    }
                }
            } elseif ($key === 'items' && is_array($value)) {
                // `items` is a subschema, except for the draft-04 tuple form, a non-empty
                // list whose members are each a subschema.
                $bad = $value === []
                    ? [...$bad, $here]
                    : [...$bad, ...array_map(static fn(int|string $i): string => "{$here}[{$i}]", array_keys(array_filter($value, is_array(...))))];
            }
            $bad = [...$bad, ...listsWhereObjectsBelong($value, $here)];
        }
    } elseif (is_array($node)) {
        foreach ($node as $i => $value) {
            $bad = [...$bad, ...listsWhereObjectsBelong($value, "{$path}[{$i}]")];
        }
    }

    return $bad;
}

test('every provider formats every raw schema without throwing or emitting a list for an object', function (string $file) {
    $schema = json_decode((string) file_get_contents($file), true);
    $tool = new SchemaTool('raw_tool', 'Raw.', $schema, fn(array $a): ToolResult => ToolResult::success('x'));

    foreach (rawSchemaFormatters() as $provider => $format) {
        $encoded = json_encode($format([$tool]), JSON_THROW_ON_ERROR);
        expect(listsWhereObjectsBelong(json_decode($encoded, false)))
            ->toBe([], "{$provider} emitted a list where an object belongs for " . basename($file));
    }
})->with(rawSchemaFixtures());

test('openai responses goes non-strict exactly for the schemas it cannot close', function () {
    $format = rawSchemaFormatters()['openai-responses'];
    $strict = [];
    foreach (rawSchemaFixtures() as $name => [$file]) {
        $tool = new SchemaTool('raw_tool', 'Raw.', json_decode((string) file_get_contents($file), true), fn(array $a): ToolResult => ToolResult::success('x'));
        $strict[$name] = $format([$tool])[0]['strict'];
    }

    expect($strict)->toBe([
        'additional-properties-schema' => false,
        'closed-empty' => true,
        'empty-object' => false,
        'nested-array-objects' => false,
        'numeric-enum' => true,
        'numeric-keys' => false,
        'one-of' => true,
        'ref-defs' => false,
        'type-arrays' => true,
        'x-mcp-header' => true,
    ]);
});

test('gemini renders a raw schema as the payload it sends', function (string $name, string $expected) {
    $format = rawSchemaFormatters()['gemini'];
    $file = __DIR__ . "/../../Fixtures/mcp-schemas/{$name}.json";
    $tool = new SchemaTool('raw_tool', 'Raw.', json_decode((string) file_get_contents($file), true), fn(array $a): ToolResult => ToolResult::success('x'));

    expect(json_encode($format([$tool]), JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
    // A nullable type array collapses to one upper-case type plus `nullable`, at depth.
    ['type-arrays', '[{"functionDeclarations":[{"name":"raw_tool","description":"Raw.","parameters":{"type":"OBJECT","properties":{"since":{"type":"STRING","nullable":true},"limit":{"type":"INTEGER","minimum":1,"nullable":true}}}}]}]'],
    // An object nested under `items`, and the free-form `{}` that must stay an object.
    ['nested-array-objects', '[{"functionDeclarations":[{"name":"raw_tool","description":"Raw.","parameters":{"type":"OBJECT","properties":{"rows":{"type":"ARRAY","items":{"type":"OBJECT","properties":{"id":{"type":"STRING"},"tags":{"type":"OBJECT","properties":{}}}}}},"required":["rows"]}}]}]'],
]);

test('llama.cpp puts required only on objects and never emits a list for properties', function () {
    $tool = new SchemaTool('raw_tool', 'Raw.', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], fn(array $a): ToolResult => ToolResult::success('x'));
    $params = (new LlamaCppToolSchemaNormalizer())->normalize([$tool])[0]->parameters;

    expect($params['required'])->toBe([])
        ->and($params['properties']['q'])->not->toHaveKey('required');
});

// The corpus is only a safety net for the providers it runs. This reads the provider
// classes off disk rather than from the corpus's own list, so a provider added to
// src/Provider fails here until someone decides which side of the line it is on.
test('the corpus runs every provider that formats a tool schema', function () {
    $exercised = ['AnthropicProvider', 'GeminiProvider', 'MistralProvider', 'OllamaProvider', 'OpenAICompatibleProvider', 'OpenAIResponsesProvider', 'XAIProvider'];
    $sendNoToolSchema = [
        // Formats tools through LlamaCppToolSchemaNormalizer, which the corpus runs directly.
        'LlamaCppProvider',
        // Hands ToolInterface objects to a CLI vendor adapter; the one adapter there is,
        // ClaudeCliVendorAdapter, runs the binary with its tools off and formats no schema.
        'CliProvider',
    ];

    $found = [];
    foreach (glob(__DIR__ . '/../../../src/Provider/*.php') ?: [] as $file) {
        $class = 'CarmeloSantana\\PHPAgents\\Provider\\' . basename($file, '.php');
        if (!class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || !$reflection->implementsInterface(CarmeloSantana\PHPAgents\Contract\ProviderInterface::class)) {
            continue;
        }
        $found[] = $reflection->getShortName();
    }
    sort($found);

    $accounted = [...$exercised, ...$sendNoToolSchema];
    sort($accounted);

    expect($found)->toBe($accounted)
        ->and(array_keys(rawSchemaFormatters()))->toHaveCount(count($exercised) + 1);
});
