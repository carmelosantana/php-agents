<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppMultimodalNormalizer;
use CarmeloSantana\PHPAgents\Provider\LlamaCpp\LlamaCppProjectorResolver;
use CarmeloSantana\PHPAgents\Provider\LlamaCppProvider;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Tests\Support\Runtime\FakeLocalModelRuntime;

test('chat aggregates runtime stream chunks into a canonical response', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'local-llama',
            name: 'Local Llama',
            family: 'llama',
            contextWindow: 8192,
            maxTokens: 4096,
            supportsTools: true,
            supportsReasoning: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                implode("\n\n", [
                    'USER: Hello runtime',
                    'ASSISTANT:',
                ]) => [
                    new RuntimeCompletionChunk(reasoning: 'Thinking...'),
                    new RuntimeCompletionChunk(content: 'Hello '),
                    new RuntimeCompletionChunk(
                        content: 'world',
                        toolCalls: [new ToolCall('call_1', 'lookup_weather', ['city' => 'Miami'])],
                        finishReason: RuntimeFinishReason::ToolUse,
                    ),
                    new RuntimeCompletionChunk(usage: new Usage(10, 4, 14)),
                ],
            ],
        ],
    );

    $provider = new LlamaCppProvider('local-llama', $runtime);

    $response = $provider->chat([new UserMessage('Hello runtime')]);

    expect($response->content)->toBe('Hello world')
        ->and($response->reasoning)->toBe('Thinking...')
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->id)->toBe('call_1')
        ->and($response->usage?->totalTokens)->toBe(14);
});

test('stream maps runtime tool-use chunks to provider responses', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'local-tools',
            name: 'Local Tools',
            supportsTools: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                implode("\n\n", [
                    'USER: Check tools',
                    'ASSISTANT:',
                ]) => [
                    new RuntimeCompletionChunk(content: 'Need a tool. '),
                    new RuntimeCompletionChunk(
                        toolCalls: [new ToolCall('call_2', 'lookup_weather', ['city' => 'San Juan'])],
                        finishReason: RuntimeFinishReason::ToolUse,
                    ),
                ],
            ],
        ],
    );

    $provider = new LlamaCppProvider('local-tools', $runtime);
    $chunks = iterator_to_array($provider->stream([new UserMessage('Check tools')]));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('Need a tool. ')
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::Stop)
        ->and($chunks[1]->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($chunks[1]->toolCalls[0]->name)->toBe('lookup_weather');
});

test('structured delegates schema to the runtime and decodes json', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'local-structured',
            name: 'Local Structured',
            defaultTemplate: 'baseline',
            extras: ['structuredOutputModes' => ['json_schema']],
        ),
        [
            'supportsStructuredOutput' => true,
            'responses' => [
                implode("\n\n", [
                    'USER: Extract Alice',
                    'ASSISTANT:',
                ]) => [
                    new RuntimeCompletionChunk(content: '{"name":"Alice"}', finishReason: RuntimeFinishReason::Stop),
                ],
            ],
        ],
    );

    $provider = new LlamaCppProvider('local-structured', $runtime);

    $result = $provider->structured(
        [new UserMessage('Extract Alice')],
        json_encode([
            'name' => 'extract_person',
            'schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ],
        ], JSON_THROW_ON_ERROR),
    );

    expect($result)->toBe(['name' => 'Alice']);
});

test('data uri images are normalized with projector and token estimate metadata', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'vision-data-uri',
            name: 'Vision Data URI',
            supportsVision: true,
            defaultTemplate: 'baseline',
            projectorPath: '/models/vision.mmproj',
            extras: ['maxImages' => 2, 'imageTokenCost' => 512],
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'seen')]]],
    );

    $provider = new LlamaCppProvider('vision-data-uri', $runtime);
    $response = $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Look'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('png-bytes')]],
            ['type' => 'text', 'text' => 'again'],
        ]),
    ]);

    expect($response->content)->toBe('seen')
        ->and($runtime->lastRequest()?->prompt)->toBe(implode("\n\n", [
            "USER: Look\n[image]\nagain",
            'ASSISTANT:',
        ]))
        ->and($runtime->lastRequest()?->images)->toHaveCount(1)
        ->and($runtime->lastRequest()?->images[0]->mimeType)->toBe('image/png')
        ->and($runtime->lastRequest()?->images[0]->bytes)->toBe('png-bytes')
        ->and($runtime->lastRequest()?->images[0]->metadata['source'])->toStartWith('data:image/png;base64,')
        ->and($runtime->lastRequest()?->options['projectorPath'])->toBe('/models/vision.mmproj')
        ->and($runtime->lastRequest()?->options['imageTokenEstimate'])->toBe(512);
});

test('local file images are loaded directly and projector can be discovered next to the model', function () {
    $directory = sys_get_temp_dir() . '/php-agents-llama-' . bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    $modelPath = $directory . '/vision.gguf';
    $projectorPath = $directory . '/vision.mmproj';
    $imagePath = $directory . '/sample.png';
    file_put_contents($modelPath, 'model');
    file_put_contents($projectorPath, 'projector');
    file_put_contents($imagePath, 'local-image');

    try {
        $runtime = new FakeLocalModelRuntime();
        $runtime->registerModel(
            new RuntimeModelMetadata(
                id: 'vision-local-file',
                name: 'Vision Local File',
                path: $modelPath,
                supportsVision: true,
                defaultTemplate: 'baseline',
            ),
            ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'loaded')]]],
        );

        $provider = new LlamaCppProvider('vision-local-file', $runtime);
        $response = $provider->chat([
            new UserMessage([
                ['type' => 'text', 'text' => 'Inspect'],
                ['type' => 'image_url', 'image_url' => ['url' => $imagePath]],
            ]),
        ]);

        expect($response->content)->toBe('loaded')
            ->and($runtime->lastRequest()?->images)->toHaveCount(1)
            ->and($runtime->lastRequest()?->images[0]->bytes)->toBe('local-image')
            ->and($runtime->lastRequest()?->options['projectorPath'])->toBe($projectorPath);
    } finally {
        @unlink($imagePath);
        @unlink($projectorPath);
        @unlink($modelPath);
        @rmdir($directory);
    }
});

test('http image inputs are downloaded through the multimodal normalizer hook', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'vision-http',
            name: 'Vision HTTP',
            supportsVision: true,
            defaultTemplate: 'baseline',
            projectorPath: '/models/http.mmproj',
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'remote')]]],
    );

    $provider = new LlamaCppProvider(
        'vision-http',
        $runtime,
        multimodalNormalizer: new LlamaCppMultimodalNormalizer(
            new LlamaCppProjectorResolver(),
            static fn(string $url): string => $url === 'https://example.com/sample.jpg' ? 'remote-image' : '',
        ),
    );

    $response = $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Remote'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/sample.jpg']],
        ]),
    ]);

    expect($response->content)->toBe('remote')
        ->and($runtime->lastRequest()?->images[0]->bytes)->toBe('remote-image')
        ->and($runtime->lastRequest()?->images[0]->mimeType)->toBe('image/jpeg');
});

test('image incapable models are rejected before generation starts', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'no-vision', name: 'No Vision', defaultTemplate: 'baseline'),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ignored')]]],
    );

    $provider = new LlamaCppProvider('no-vision', $runtime);

    expect(fn() => $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'Look'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('abc')]],
        ]),
    ]))->toThrow(InvalidArgumentException::class, 'does not support image input');
});

test('one image models reject multiple image inputs', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'single-image',
            name: 'Single Image',
            supportsVision: true,
            defaultTemplate: 'baseline',
            projectorPath: '/models/single.mmproj',
            extras: ['maxImages' => 1],
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ignored')]]],
    );

    $provider = new LlamaCppProvider('single-image', $runtime);

    expect(fn() => $provider->chat([
        new UserMessage([
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('a')]],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('b')]],
        ]),
    ]))->toThrow(InvalidArgumentException::class, 'supports at most 1 image');
});

test('prompt and image ordering is preserved across multiple image blocks', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'ordered-images',
            name: 'Ordered Images',
            supportsVision: true,
            defaultTemplate: 'baseline',
            projectorPath: '/models/ordered.mmproj',
            extras: ['maxImages' => 2],
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ordered')]]],
    );

    $provider = new LlamaCppProvider('ordered-images', $runtime);
    $provider->chat([
        new UserMessage([
            ['type' => 'text', 'text' => 'First'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('one')]],
            ['type' => 'text', 'text' => 'Second'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('two')]],
        ]),
    ]);

    expect($runtime->lastRequest()?->prompt)->toBe(implode("\n\n", [
        "USER: First\n[image]\nSecond\n[image]",
        'ASSISTANT:',
    ]))
        ->and($runtime->lastRequest()?->images)->toHaveCount(2)
        ->and($runtime->lastRequest()?->images[0]->metadata['imageIndex'])->toBe(0)
        ->and($runtime->lastRequest()?->images[1]->metadata['imageIndex'])->toBe(1)
        ->and($runtime->lastRequest()?->images[0]->bytes)->toBe('one')
        ->and($runtime->lastRequest()?->images[1]->bytes)->toBe('two');
});

test('models reflects runtime metadata as model definitions', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(
        id: 'local-discovered',
        name: 'Local Discovered',
        path: '/models/local.gguf',
        family: 'llama',
        contextWindow: 16384,
        maxTokens: 4096,
        supportsTools: true,
        supportsVision: true,
        supportsReasoning: true,
        supportsThinking: true,
        projectorPath: '/models/local.mmproj',
        defaultTemplate: 'chatml',
        defaultToolParser: 'json',
        aliases: ['local-alias'],
    ));

    $provider = new LlamaCppProvider('local-discovered', $runtime);
    $models = $provider->models();

    expect($models)->toHaveCount(1)
        ->and($models[0])->toBeInstanceOf(ModelDefinition::class)
        ->and($models[0]->provider)->toBe('llama-cpp')
        ->and($models[0]->toolCalls)->toBeTrue()
        ->and($models[0]->vision)->toBeTrue()
        ->and($models[0]->reasoning)->toBeTrue()
        ->and($models[0]->thinking)->toBeTrue()
        ->and($models[0]->alias)->toBe('local-alias')
        ->and($models[0]->extras['path'])->toBe('/models/local.gguf');
});

test('withModel returns a cloned provider with a different target model', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(id: 'first', name: 'First', defaultTemplate: 'baseline'));
    $runtime->registerModel(new RuntimeModelMetadata(id: 'second', name: 'Second', defaultTemplate: 'baseline'));

    $provider = new LlamaCppProvider('first', $runtime);
    $clone = $provider->withModel('second');

    expect($provider->getModel())->toBe('first')
        ->and($clone->getModel())->toBe('second');
});

test('prompt rendering preserves assistant tool calls and tool results in the baseline format', function () {
    $toolResult = ToolResult::json(['status' => 'ok'])->withCallId('call_3');
    $expectedPrompt = implode("\n\n", [
        'USER: Read the file',
        'ASSISTANT: ',
        'ASSISTANT_TOOL_CALLS: [{"id":"call_3","name":"read_file","arguments":{"path":"/tmp/demo.txt"}}]',
        'TOOL: ' . $toolResult->content,
        'TOOL_CALL_ID: call_3',
        'ASSISTANT:',
    ]);

    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'local-history', name: 'Local History', defaultTemplate: 'baseline'),
        [
            'responses' => [
                '*' => [new RuntimeCompletionChunk(content: 'Summarized.')],
            ],
        ],
    );

    $provider = new LlamaCppProvider('local-history', $runtime);

    $response = $provider->chat([
        new UserMessage('Read the file'),
        new AssistantMessage('', [new ToolCall('call_3', 'read_file', ['path' => '/tmp/demo.txt'])]),
        new ToolResultMessage($toolResult),
    ]);

    expect($response->content)->toBe('Summarized.')
        ->and($runtime->lastRequest()?->prompt)->toBe($expectedPrompt);
});

test('tools are normalized into runtime tool definitions', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'local-tool-schema',
            name: 'Local Tool Schema',
            supportsTools: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                '*' => [
                    new RuntimeCompletionChunk(
                        toolCalls: [new ToolCall('call_4', 'echo_text', ['text' => 'ok'])],
                        finishReason: RuntimeFinishReason::ToolUse,
                    ),
                ],
            ],
        ],
    );

    $provider = new LlamaCppProvider('local-tool-schema', $runtime);
    $tool = new Tool(
        name: 'echo_text',
        description: 'Echo text',
        parameters: [new StringParameter('text', 'Text to echo', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success($input['text'] ?? ''),
    );

    $response = $provider->chat([new UserMessage('Use a tool')], [$tool]);

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('echo_text')
        ->and($runtime->lastRequest()?->prompt)->toContain('Available tools:')
        ->and($runtime->lastRequest()?->prompt)->toContain('"echo_text"')
        ->and($runtime->lastRequest()?->options['template'])->toBe('baseline')
        ->and($runtime->lastRequest()?->options['toolParser'])->toBe('json')
        ->and($runtime->lastRequest()?->tools[0]->parameters['required'])->toBe(['text']);
});

test('strict structured output fails explicitly when unsupported', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'strict-unsupported', name: 'Strict Unsupported', defaultTemplate: 'baseline'),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: '{}')]]],
    );

    $provider = new LlamaCppProvider('strict-unsupported', $runtime);

    expect(fn() => $provider->structured(
        [new UserMessage('Extract data')],
        json_encode(['name' => 'extract', 'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]], JSON_THROW_ON_ERROR),
    ))->toThrow(RuntimeException::class, 'Strict structured output mode');
});

test('strict structured output normalizes empty object schemas before runtime dispatch', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'strict-normalized',
            name: 'Strict Normalized',
            defaultTemplate: 'baseline',
            extras: ['structuredOutputModes' => ['json_schema']],
        ),
        [
            'supportsStructuredOutput' => true,
            'responses' => ['*' => [new RuntimeCompletionChunk(content: '{}')]],
        ],
    );

    $provider = new LlamaCppProvider('strict-normalized', $runtime);
    $result = $provider->structured(
        [new UserMessage('Return empty object')],
        json_encode(['name' => 'empty', 'schema' => ['type' => 'object']], JSON_THROW_ON_ERROR),
    );

    expect($result)->toBe([])
        ->and($runtime->lastRequest()?->structuredOutput?->strict)->toBeTrue()
        ->and($runtime->lastRequest()?->structuredOutput?->mode)->toBe('json_schema')
        ->and($runtime->lastRequest()?->structuredOutput?->schema)->toMatchArray([
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ]);
});

test('invalid strict structured output root schema is rejected', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'invalid-structured',
            name: 'Invalid Structured',
            defaultTemplate: 'baseline',
            extras: ['structuredOutputModes' => ['json_schema']],
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: '{}')]]],
    );

    $provider = new LlamaCppProvider('invalid-structured', $runtime);

    expect(fn() => $provider->structured(
        [new UserMessage('Return a list')],
        json_encode(['name' => 'bad', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]], JSON_THROW_ON_ERROR),
    ))->toThrow(InvalidArgumentException::class, 'root schema must declare type "object"');
});

test('named lax structured output mode falls back to best effort json extraction', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'lax-structured', name: 'Lax Structured', defaultTemplate: 'baseline'),
        [
            'responses' => ['*' => [new RuntimeCompletionChunk(content: 'Here is JSON: {"name":"Alice"}')]],
        ],
    );

    $provider = new LlamaCppProvider('lax-structured', $runtime);
    $result = $provider->structured(
        [new UserMessage('Extract Alice')],
        json_encode(['name' => 'extract', 'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]], JSON_THROW_ON_ERROR),
        ['strict' => false, 'structured_mode' => 'json_best_effort'],
    );

    expect($result)->toBe(['name' => 'Alice'])
        ->and($runtime->lastRequest()?->structuredOutput)->toBeNull()
        ->and($runtime->lastRequest()?->prompt)->toContain('Return valid JSON only matching this schema');
});

test('missing template fails explicitly', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(new RuntimeModelMetadata(id: 'template-missing', name: 'Template Missing'));

    $provider = new LlamaCppProvider('template-missing', $runtime);

    expect(fn() => $provider->chat([new UserMessage('Hi')]))
        ->toThrow(RuntimeException::class, 'No llama.cpp chat template resolved');
});

test('model template override wins over runtime metadata and built in template defaults', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'template-precedence',
            name: 'Template Precedence',
            family: 'llama',
            defaultTemplate: 'chatml',
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ok')]]],
    );

    $provider = new LlamaCppProvider('template-precedence', $runtime, [
        'modelTemplate' => 'baseline',
        'builtInTemplate' => 'raw',
    ]);

    $provider->chat([new UserMessage('Template precedence')]);

    expect($runtime->lastRequest()?->prompt)->toBe(implode("\n\n", [
        'USER: Template precedence',
        'ASSISTANT:',
    ]));
});

test('runtime metadata template wins over built in template default when no model override exists', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'metadata-template',
            name: 'Metadata Template',
            defaultTemplate: 'chatml',
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ok')]]],
    );

    $provider = new LlamaCppProvider('metadata-template', $runtime, ['builtInTemplate' => 'baseline']);
    $provider->chat([new UserMessage('Use chatml')]);

    expect($runtime->lastRequest()?->prompt)->toBe(implode("\n\n", [
        '<|user|>' . "\n" . 'Use chatml',
        '<|assistant|>',
    ]));
});

test('chatml formatting preserves assistant tool calls and tool results', function () {
    $toolResult = ToolResult::json(['status' => 'ok'])->withCallId('call_9');
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'chatml-history', name: 'ChatML History', defaultTemplate: 'chatml'),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'done')]]],
    );

    $provider = new LlamaCppProvider('chatml-history', $runtime);
    $provider->chat([
        new UserMessage('Replay this'),
        new AssistantMessage('', [new ToolCall('call_9', 'read_file', ['path' => '/tmp/chatml.txt'])]),
        new ToolResultMessage($toolResult),
    ]);

    expect($runtime->lastRequest()?->prompt)->toBe(implode("\n\n", [
        '<|user|>' . "\n" . 'Replay this',
        '<|assistant|>' . "\n",
        '<|assistant_tool_calls|>' . "\n" . '[{"id":"call_9","name":"read_file","arguments":{"path":"/tmp/chatml.txt"}}]',
        '<|tool|>' . "\n" . $toolResult->content,
        '<|tool_call_id|>' . "\n" . 'call_9',
        '<|assistant|>',
    ]));
});

test('one shot json tool call content is parsed into canonical tool calls', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'json-tool-call',
            name: 'JSON Tool Call',
            supportsTools: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                '*' => [
                    new RuntimeCompletionChunk(
                        content: '{"tool_calls":[{"name":"lookup_weather","arguments":{"city":"Miami"}}]}',
                        finishReason: RuntimeFinishReason::ToolUse,
                    ),
                ],
            ],
        ],
    );

    $tool = new Tool(
        name: 'lookup_weather',
        description: 'Lookup weather',
        parameters: [new StringParameter('city', 'City', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );

    $provider = new LlamaCppProvider('json-tool-call', $runtime);
    $response = $provider->chat([new UserMessage('Weather?')], [$tool]);

    expect($response->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('lookup_weather')
        ->and($response->toolCalls[0]->id)->toStartWith('call_')
        ->and($response->toolCalls[0]->arguments)->toBe(['city' => 'Miami']);
});

test('fragmented json tool call stream is assembled before yielding canonical tool calls', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'fragmented-tools',
            name: 'Fragmented Tools',
            supportsTools: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                '*' => [
                    new RuntimeCompletionChunk(content: '{"tool_calls":[{"id":"call_10","name":"lookup_weather","arguments":{"ci'),
                    new RuntimeCompletionChunk(content: 'ty":"San Juan"}}]}', finishReason: RuntimeFinishReason::ToolUse),
                ],
            ],
        ],
    );

    $tool = new Tool(
        name: 'lookup_weather',
        description: 'Lookup weather',
        parameters: [new StringParameter('city', 'City', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );

    $provider = new LlamaCppProvider('fragmented-tools', $runtime);
    $chunks = iterator_to_array($provider->stream([new UserMessage('Need weather')], [$tool]));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->finishReason)->toBe(ProviderFinishReason::ToolUse)
        ->and($chunks[0]->toolCalls[0]->id)->toBe('call_10')
        ->and($chunks[0]->toolCalls[0]->arguments)->toBe(['city' => 'San Juan']);
});

test('multiple json tool calls in one turn are parsed into canonical tool calls', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'multiple-tools',
            name: 'Multiple Tools',
            supportsTools: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                '*' => [
                    new RuntimeCompletionChunk(
                        content: '{"tool_calls":['
                            . '{"id":"call_11","name":"first_tool","arguments":{"value":"a"}},'
                            . '{"id":"call_12","name":"second_tool","arguments":{"value":"b"}}'
                            . ']}',
                        finishReason: RuntimeFinishReason::ToolUse,
                    ),
                ],
            ],
        ],
    );

    $first = new Tool(
        name: 'first_tool',
        description: 'First tool',
        parameters: [new StringParameter('value', 'Value', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );
    $second = new Tool(
        name: 'second_tool',
        description: 'Second tool',
        parameters: [new StringParameter('value', 'Value', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );

    $provider = new LlamaCppProvider('multiple-tools', $runtime);
    $response = $provider->chat([new UserMessage('Use both tools')], [$first, $second]);

    expect($response->toolCalls)->toHaveCount(2)
        ->and($response->toolCalls[0]->name)->toBe('first_tool')
        ->and($response->toolCalls[1]->name)->toBe('second_tool');
});

test('parser mismatch throws by default and never emits malformed tool calls', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'parser-error',
            name: 'Parser Error',
            supportsTools: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                '*' => [new RuntimeCompletionChunk(content: '{"tool_calls":[', finishReason: RuntimeFinishReason::ToolUse)],
            ],
        ],
    );

    $tool = new Tool(
        name: 'broken_tool',
        description: 'Broken tool',
        parameters: [new StringParameter('value', 'Value', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );

    $provider = new LlamaCppProvider('parser-error', $runtime);

    expect(fn() => iterator_to_array($provider->stream([new UserMessage('Break parser')], [$tool])))
        ->toThrow(RuntimeException::class, 'Failed to parse llama.cpp tool call output');
});

test('parser mismatch can fall back to content with named policy', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'parser-fallback',
            name: 'Parser Fallback',
            supportsTools: true,
            defaultTemplate: 'baseline',
        ),
        [
            'responses' => [
                '*' => [new RuntimeCompletionChunk(content: '{"tool_calls":[', finishReason: RuntimeFinishReason::ToolUse)],
            ],
        ],
    );

    $tool = new Tool(
        name: 'broken_tool',
        description: 'Broken tool',
        parameters: [new StringParameter('value', 'Value', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );

    $provider = new LlamaCppProvider('parser-fallback', $runtime);
    $response = $provider->chat(
        [new UserMessage('Fallback parser')],
        [$tool],
        ['tool_parser_failure_policy' => 'content'],
    );

    expect($response->content)->toBe('{"tool_calls":[')
        ->and($response->toolCalls)->toBe([])
        ->and($response->finishReason)->toBe(ProviderFinishReason::Stop);
});

test('tool requests fail when no parser is available for the selected template', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(
            id: 'parser-disabled',
            name: 'Parser Disabled',
            supportsTools: true,
            defaultTemplate: 'raw',
        ),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ignored')]]],
    );

    $tool = new Tool(
        name: 'echo_text',
        description: 'Echo text',
        parameters: [new StringParameter('text', 'Text', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );

    $provider = new LlamaCppProvider('parser-disabled', $runtime);

    expect(fn() => $provider->chat([new UserMessage('Use a tool')], [$tool]))
        ->toThrow(RuntimeException::class, 'has no configured llama.cpp tool parser');
});

test('tool requests fail for models that do not support tools', function () {
    $runtime = new FakeLocalModelRuntime();
    $runtime->registerModel(
        new RuntimeModelMetadata(id: 'no-tools', name: 'No Tools', defaultTemplate: 'baseline'),
        ['responses' => ['*' => [new RuntimeCompletionChunk(content: 'ignored')]]],
    );

    $tool = new Tool(
        name: 'echo_text',
        description: 'Echo text',
        parameters: [new StringParameter('text', 'Text', required: true)],
        callback: fn(array $input): ToolResult => ToolResult::success('ok'),
    );

    $provider = new LlamaCppProvider('no-tools', $runtime);

    expect(fn() => $provider->chat([new UserMessage('Use a tool')], [$tool]))
        ->toThrow(InvalidArgumentException::class, 'does not support tools');
});