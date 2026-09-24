<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
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
// Which providers "every" names is not left to this comment: the test named
// "the corpus runs every provider that formats a tool schema" reads the concrete providers off
// disk and fails when one is neither exercised here nor listed there as sending no tool schema.

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
 * node that a provider stripped down to nothing ends up, and the provider envelope keys
 * (`parameters`, `input_schema`) that carry the root schema, which nothing in the vocabulary
 * names.
 *
 * The keyword lists are transcribed from the JSON Schema vocabulary — 2020-12 plus the older
 * `definitions` and `additionalItems` — and not from any list a provider keeps, so a keyword a
 * provider forgot to walk is still checked here. Two honest limits on that independence:
 *
 * - Minus the envelope keys and the member and tuple positions, these keyword names coincide
 *   with JsonSchemaRepair's own constants. Both are transcriptions of the same published
 *   vocabulary, so that is expected rather than derived — but it does mean a schema position
 *   missing from *both* is invisible to both, and this oracle would not catch it.
 * - Draft-07 `dependencies` is deliberately absent. Its members mix schemas with string lists,
 *   so a member that decoded as `[]` may legitimately be an empty list of property names;
 *   flagging it would make this oracle report defects that are not there. JsonSchemaRepair
 *   skips it for the same reason.
 */
function listsWhereObjectsBelong(mixed $node, string $path = '$'): array
{
    $maps = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];
    $subschemas = ['additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'contains', 'not', 'if', 'then', 'else', 'propertyNames', 'contentSchema', 'additionalItems', 'parameters', 'input_schema'];
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

/**
 * Where each formatter puts the one tool it was given: [path to its schema, path to its name].
 *
 * The corpus's other assertion is a negative, which an empty payload satisfies. These paths are
 * the positive half — a formatter that dropped the tool, or dropped its schema, fails here. The
 * llama-cpp entry has no name path because that formatter projects only `parameters`.
 *
 * @return array<string, array{string, ?string}>
 */
function rawSchemaToolPins(): array
{
    return [
        'openai-chat' => ['function.parameters', 'function.name'],
        'openai-responses' => ['parameters', 'name'],
        'mistral' => ['function.parameters', 'function.name'],
        'xai' => ['function.parameters', 'function.name'],
        'anthropic' => ['input_schema', 'name'],
        'ollama' => ['function.parameters', 'function.name'],
        'gemini' => ['functionDeclarations.0.parameters', 'functionDeclarations.0.name'],
        'llama-cpp' => ['parameters', null],
    ];
}

/** Read a dotted path off a json_decode(..., false) tree; a numeric step indexes a list. */
function atSchemaPath(mixed $node, string $path): mixed
{
    foreach (explode('.', $path) as $step) {
        $node = match (true) {
            is_array($node) => $node[(int) $step] ?? null,
            $node instanceof stdClass => $node->{$step} ?? null,
            default => null,
        };
    }

    return $node;
}

test('every provider formats every raw schema without throwing or emitting a list for an object', function (string $file) {
    $schema = json_decode((string) file_get_contents($file), true);
    $tool = new SchemaTool('raw_tool', 'Raw.', $schema, fn(array $a): ToolResult => ToolResult::success('x'));

    foreach (rawSchemaFormatters() as $provider => $format) {
        $encoded = json_encode($format([$tool]), JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, false);
        [$schemaPath, $namePath] = rawSchemaToolPins()[$provider];

        expect($decoded)->toBeArray()->toHaveCount(1, "{$provider} did not emit exactly one tool for " . basename($file));
        expect(atSchemaPath($decoded[0], $schemaPath))
            ->toBeInstanceOf(stdClass::class, "{$provider} emitted no schema at {$schemaPath} for " . basename($file));
        if ($namePath !== null) {
            expect(atSchemaPath($decoded[0], $namePath))->toBe('raw_tool', "{$provider} lost the tool name for " . basename($file));
        }
        expect(listsWhereObjectsBelong($decoded))
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
    // `who` carries only `$ref`, which Gemini strips; the node that is left is `{}`, not `[]`.
    ['ref-defs', '[{"functionDeclarations":[{"name":"raw_tool","description":"Raw.","parameters":{"type":"OBJECT","properties":{"who":{}}}}]}]'],
    // `target` carries only `oneOf`, which Gemini's Schema has no field for; the node that is left is `{}`.
    ['one-of', '[{"functionDeclarations":[{"name":"raw_tool","description":"Raw.","parameters":{"type":"OBJECT","properties":{"target":{}}}}]}]'],
]);

/**
 * A tool whose toFunctionSchema() puts something other than a schema array in `parameters`.
 *
 * SchemaTool and Tool both always produce an array there, so the normalizer's fallback is not
 * reachable through them — but ToolInterface is public and its implementations are not all in
 * this repo, which is what the fallback is for.
 */
final class NonArrayParametersTool implements ToolInterface
{
    public function name(): string
    {
        return 'raw_tool';
    }

    public function description(): string
    {
        return 'Raw.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        return ToolResult::success('x');
    }

    /** @return array<string, mixed> */
    public function toFunctionSchema(): array
    {
        return ['type' => 'function', 'function' => ['name' => 'raw_tool', 'description' => 'Raw.', 'parameters' => 'not a schema']];
    }
}

test('llama.cpp falls back to a closed, empty object when parameters are not a schema array', function () {
    $parameters = (new LlamaCppToolSchemaNormalizer())->normalize([new NonArrayParametersTool()])[0]->parameters;

    expect(json_encode($parameters, JSON_THROW_ON_ERROR))
        ->toBe('{"type":"object","properties":{},"additionalProperties":false,"required":[]}');
});

test('llama.cpp puts required only on objects and never emits a list for properties', function () {
    $tool = new SchemaTool('raw_tool', 'Raw.', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], fn(array $a): ToolResult => ToolResult::success('x'));
    $params = (new LlamaCppToolSchemaNormalizer())->normalize([$tool])[0]->parameters;

    expect($params['required'])->toBe([])
        ->and($params['properties']['q'])->not->toHaveKey('required');
});

// The corpus is only a safety net for the providers it runs. This reads the provider classes
// off disk rather than from the corpus's own list, so a provider added anywhere under
// src/Provider — subdirectories included — fails here until someone decides which side of the
// line it is on.
test('the corpus runs every provider that formats a tool schema', function () {
    $exercised = ['AnthropicProvider', 'GeminiProvider', 'MistralProvider', 'OllamaProvider', 'OpenAICompatibleProvider', 'OpenAIResponsesProvider', 'XAIProvider'];
    $sendNoToolSchema = [
        // Formats tools through LlamaCppToolSchemaNormalizer, which the corpus runs directly.
        'LlamaCppProvider',
        // Hands ToolInterface objects to a CLI vendor adapter; the one adapter there is,
        // ClaudeCliVendorAdapter, runs the binary with its tools off and formats no schema.
        'CliProvider',
    ];

    // Recursive, because src/Provider already has subdirectories (LlamaCpp/, Cli/) and a
    // provider put in one of them must not slip past this. The namespace is derived from the
    // path under src/Provider, which is how the PSR-4 autoloader maps them.
    $root = (string) realpath(__DIR__ . '/../../../src/Provider');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $found = [];
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $class = 'CarmeloSantana\\PHPAgents\\Provider\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
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

// The predicate is the corpus's oracle, so it gets its own probes: a synthetic `[]` planted at
// each position class must be reported, and the draft-04 `items` tuple must not be.
test('the predicate reports a list planted at any schema position', function (string $payload, array $expected) {
    expect(listsWhereObjectsBelong(json_decode($payload, false)))->toBe($expected);
})->with([
    'openai envelope' => ['[{"function":{"name":"t","parameters":[]}}]', ['$[0].function.parameters']],
    'anthropic envelope' => ['[{"name":"t","input_schema":[]}]', ['$[0].input_schema']],
    'responses envelope' => ['[{"name":"t","parameters":[]}]', ['$[0].parameters']],
    'properties map' => ['{"properties":[]}', ['$.properties']],
    'properties member' => ['{"properties":{"a":[]}}', ['$.properties.a']],
    'items subschema' => ['{"items":[]}', ['$.items']],
    'additionalProperties' => ['{"additionalProperties":[]}', ['$.additionalProperties']],
    'oneOf member' => ['{"oneOf":[[],{"type":"string"}]}', ['$.oneOf[0]']],
    '$defs member' => ['{"$defs":{"person":[]}}', ['$.$defs.person']],
    'draft-04 items tuple' => ['{"items":[{"type":"string"},{"type":"number"}]}', []],
    'a clean payload' => ['[{"function":{"name":"t","parameters":{"type":"object","properties":{"a":{"type":"string"}},"required":["a"]}}}]', []],
]);
