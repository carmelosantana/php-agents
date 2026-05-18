<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPAgents\Runtime\LlamaCpp;

use CarmeloSantana\PHPAgents\Enum\RuntimeFinishReason;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionChunk;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionResult;
use CarmeloSantana\PHPAgents\Runtime\RuntimeImageInput;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

/**
 * @phpstan-type NativeModelHandle object{path: string, pointer: mixed}&\stdClass
 * @phpstan-type NativeContextHandle object{pointer: mixed, batchSize: int, threads: int, mtmd: mixed, mtmdProjectorPath: ?string}&\stdClass
 */
final class FfiLlamaCppNativeApi implements LlamaCppNativeApiInterface
{
    private const int INT32_MIN = -2147483648;

    private ?\FFI $ffi = null;

    private ?\FFI $mtmdFfi = null;

    private bool $backendInitialized = false;

    private readonly string $mtmdLibraryPath;

    private readonly LlamaCppStructuredOutputValidator $structuredOutputValidator;

    public function __construct(
        private readonly string $libraryPath,
        ?string $mtmdLibraryPath = null,
        ?LlamaCppStructuredOutputValidator $structuredOutputValidator = null,
    ) {
        $this->mtmdLibraryPath = $mtmdLibraryPath ?? $libraryPath;
        $this->structuredOutputValidator = $structuredOutputValidator ?? new LlamaCppStructuredOutputValidator();
    }

    public function isAvailable(): bool
    {
        if (!extension_loaded('FFI') || $this->libraryPath === '') {
            return false;
        }

        try {
            $this->ffi();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function backendInit(): void
    {
        if ($this->backendInitialized) {
            return;
        }

        $this->symbol('llama_backend_init');
        $this->backendInitialized = true;
    }

    /**
     * @return NativeModelHandle
     */
    public function openModel(string $path, array $options = []): object
    {
        $this->backendInit();

        $params = $this->symbol('llama_model_default_params');
        $params->n_gpu_layers = (int) ($options['gpuLayers'] ?? $options['n_gpu_layers'] ?? 0);
        $params->main_gpu = (int) ($options['mainGpu'] ?? $options['main_gpu'] ?? 0);
        $params->use_mmap = (bool) ($options['useMmap'] ?? $options['use_mmap'] ?? true);
        $params->use_mlock = (bool) ($options['useMlock'] ?? $options['use_mlock'] ?? false);
        $params->no_alloc = (bool) ($options['noAlloc'] ?? $options['no_alloc'] ?? false);
        $params->vocab_only = (bool) ($options['vocabOnly'] ?? $options['vocab_only'] ?? false);

        $modelPointer = $this->symbol('llama_model_load_from_file', $path, $params);
        if (\FFI::isNull($modelPointer)) {
            throw new \RuntimeException("Failed to load llama.cpp model from {$path}.");
        }

        return (object) [
            'path' => $path,
            'pointer' => $modelPointer,
        ];
    }

    public function describeModel(object $model, string $fallbackId, string $path, array $options = []): RuntimeModelMetadata
    {
        $modelPointer = $this->modelPointer($model);
        $contextWindow = max(1, (int) $this->symbol('llama_model_n_ctx_train', $modelPointer));
        $description = $this->readModelDescription($model) ?? basename($path);
        $family = $this->readModelMetadataValue($model, 'general.architecture');
        $template = $this->readOptionalCString($this->symbol('llama_model_chat_template', $modelPointer, null));
        $projectorPath = is_string($options['projectorPath'] ?? null) ? $options['projectorPath'] : null;
        $multimodalCaps = $projectorPath !== null && $projectorPath !== '' ? $this->readMtmdCaps($projectorPath) : null;
        $structuredModes = $this->normalizeStringList($options['structuredOutputModes'] ?? null);

        return new RuntimeModelMetadata(
            id: $fallbackId,
            name: $description,
            path: $path,
            family: $family,
            contextWindow: $contextWindow,
            maxTokens: max(1, (int) ($options['maxTokens'] ?? $contextWindow)),
            supportsTools: (bool) ($options['supportsTools'] ?? false),
            supportsVision: (bool) ($options['supportsVision'] ?? $multimodalCaps['vision'] ?? false),
            supportsReasoning: (bool) ($options['supportsReasoning'] ?? false),
            supportsThinking: (bool) ($options['supportsThinking'] ?? false),
            projectorPath: $projectorPath,
            defaultTemplate: $template,
            defaultToolParser: is_string($options['defaultToolParser'] ?? null) ? $options['defaultToolParser'] : null,
            aliases: $this->normalizeStringList($options['aliases'] ?? null),
            extras: array_filter([
                'libraryPath' => $this->libraryPath,
                'description' => $description,
                'supportsStructuredOutput' => (bool) ($options['supportsStructuredOutput'] ?? !empty($structuredModes)),
                'structuredOutputModes' => $structuredModes,
                'mtmdLibraryPath' => $this->mtmdLibraryPath,
                'supportsAudioInput' => $multimodalCaps['audio'] ?? false,
            ], static fn(mixed $value): bool => $value !== [] && $value !== ''),
        );
    }

    /**
     * @param NativeModelHandle $model
     * @return NativeContextHandle
     */
    public function openContext(object $model, array $options = []): object
    {
        $params = $this->symbol('llama_context_default_params');
        $params->n_ctx = (int) ($options['numCtx'] ?? $options['n_ctx'] ?? 0);
        $params->n_batch = max(1, (int) ($options['batchSize'] ?? $options['n_batch'] ?? 512));
        $params->n_ubatch = max(1, (int) ($options['ubatchSize'] ?? $options['n_ubatch'] ?? $params->n_batch));
        $params->n_seq_max = max(1, (int) ($options['maxSequences'] ?? $options['n_seq_max'] ?? 1));
        $params->n_threads = max(1, (int) ($options['threads'] ?? $options['n_threads'] ?? 1));
        $params->n_threads_batch = max(1, (int) ($options['batchThreads'] ?? $options['n_threads_batch'] ?? $params->n_threads));
        $params->embeddings = (bool) ($options['embeddings'] ?? false);
        $params->offload_kqv = (bool) ($options['offloadKqv'] ?? false);
        $params->no_perf = (bool) ($options['noPerf'] ?? true);

        $contextPointer = $this->symbol('llama_init_from_model', $this->modelPointer($model), $params);
        if (\FFI::isNull($contextPointer)) {
            throw new \RuntimeException('Failed to initialize llama.cpp context.');
        }

        return (object) [
            'pointer' => $contextPointer,
            'batchSize' => (int) $params->n_batch,
            'threads' => (int) $params->n_threads,
            'mtmd' => null,
            'mtmdProjectorPath' => null,
        ];
    }

    /**
     * @param NativeContextHandle $context
     */
    public function closeContext(object $context): void
    {
        if (($context->mtmd ?? null) !== null && !\FFI::isNull($context->mtmd)) {
            $this->mtmdSymbol('mtmd_free', $context->mtmd);
            $context->mtmd = null;
        }

        $contextPointer = $this->contextPointer($context);
        if (!\FFI::isNull($contextPointer)) {
            $this->symbol('llama_free', $contextPointer);
        }
    }

    public function closeModel(object $model): void
    {
        $modelPointer = $this->modelPointer($model);
        if (!\FFI::isNull($modelPointer)) {
            $this->symbol('llama_model_free', $modelPointer);
        }
    }

    public function tokenize(object $model, string $text, bool $addSpecial = true, bool $parseSpecial = false): array
    {
        $vocab = $this->symbol('llama_model_get_vocab', $this->modelPointer($model));
        $nullTokens = $this->cast('llama_token *', 0);
        $result = $this->symbol(
            'llama_tokenize',
            $vocab,
            $text,
            strlen($text),
            $nullTokens,
            0,
            $addSpecial,
            $parseSpecial,
        );

        if ($result === self::INT32_MIN) {
            throw new \RuntimeException('llama.cpp tokenization overflowed int32 range.');
        }

        $required = $result < 0 ? -$result : $result;
        if ($required === 0) {
            return [];
        }

        $tokens = $this->allocate("llama_token[{$required}]");
        $written = $this->symbol(
            'llama_tokenize',
            $vocab,
            $text,
            strlen($text),
            $tokens,
            $required,
            $addSpecial,
            $parseSpecial,
        );

        if ($written < 0) {
            throw new \RuntimeException('llama.cpp tokenization failed on second pass.');
        }

        $resultTokens = [];
        for ($index = 0; $index < $written; $index++) {
            $resultTokens[] = (int) $tokens[$index];
        }

        return $resultTokens;
    }

    public function detokenize(object $model, array $tokens, bool $removeSpecial = true, bool $unparseSpecial = false): string
    {
        $vocab = $this->symbol('llama_model_get_vocab', $this->modelPointer($model));
        if ($tokens === []) {
            return '';
        }

        $tokenBuffer = $this->allocate('llama_token[' . count($tokens) . ']');
        foreach (array_values($tokens) as $index => $token) {
            $tokenBuffer[$index] = (int) $token;
        }

        $nullText = $this->cast('char *', 0);
        $result = $this->symbol(
            'llama_detokenize',
            $vocab,
            $tokenBuffer,
            count($tokens),
            $nullText,
            0,
            $removeSpecial,
            $unparseSpecial,
        );

        if ($result === self::INT32_MIN) {
            throw new \RuntimeException('llama.cpp detokenization overflowed int32 range.');
        }

        $required = $result < 0 ? -$result : $result;
        $textBuffer = $this->allocate('char[' . max(1, $required) . ']');
        $written = $this->symbol(
            'llama_detokenize',
            $vocab,
            $tokenBuffer,
            count($tokens),
            $textBuffer,
            max(1, $required),
            $removeSpecial,
            $unparseSpecial,
        );

        if ($written < 0) {
            throw new \RuntimeException('llama.cpp detokenization failed on second pass.');
        }

        return $this->cString($textBuffer, $written);
    }

    /**
     * @param NativeModelHandle $model
     * @param NativeContextHandle $context
     */
    public function generate(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
    ): RuntimeCompletionResult {
        return RuntimeCompletionResult::fromChunks($this->stream($model, $context, $metadata, $request));
    }

    /**
     * @param NativeModelHandle $model
     * @param NativeContextHandle $context
     */
    public function stream(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
    ): iterable {
        $modelPointer = $this->modelPointer($model);
        $contextPointer = $this->contextPointer($context);
        $batchSize = max(1, (int) ($request->options['batchSize'] ?? $context->batchSize ?? 512));
        $parseSpecial = (bool) ($request->options['parseSpecial'] ?? true);
        $addSpecial = (bool) ($request->options['addSpecial'] ?? true);
        $reuseState = (bool) ($request->options['reuseState'] ?? false);
        $maxTokens = $this->resolveMaxTokens($metadata, $request);
        $grammar = $this->resolveGrammar($metadata, $request);
        $bufferStructured = $request->structuredOutput !== null && $request->structuredOutput->strict;

        if (!$reuseState) {
            $this->clearContextMemory($contextPointer);
        }

        $promptTokens = $request->images === []
            ? $this->evaluateTextPrompt($model, $contextPointer, $request->prompt, $batchSize, $addSpecial, $parseSpecial)
            : $this->evaluateMultimodalPrompt($model, $context, $metadata, $request, $batchSize, $addSpecial, $parseSpecial);

        $sampler = $this->createSampler($modelPointer, $request, $grammar);
        $generatedTokens = [];
        $buffer = '';
        $finishReason = RuntimeFinishReason::Stop;

        try {
            $vocab = $this->symbol('llama_model_get_vocab', $modelPointer);

            for ($index = 0; $index < $maxTokens; $index++) {
                $token = (int) $this->symbol('llama_sampler_sample', $sampler, $contextPointer, -1);
                if ((bool) $this->symbol('llama_vocab_is_eog', $vocab, $token)) {
                    $finishReason = RuntimeFinishReason::Stop;
                    break;
                }

                $generatedTokens[] = $token;
                $piece = $this->detokenize($model, [$token], false, true);
                $buffer .= $piece;

                $this->decodeTokens($contextPointer, [$token]);

                if (!$bufferStructured && $piece !== '') {
                    yield new RuntimeCompletionChunk(content: $piece);
                }

                if ($index === $maxTokens - 1) {
                    $finishReason = RuntimeFinishReason::MaxTokens;
                }
            }
        } finally {
            $this->symbol('llama_sampler_free', $sampler);
        }

        if ($request->structuredOutput !== null && $request->structuredOutput->strict) {
            $this->structuredOutputValidator->decodeAndValidate($buffer, $request->structuredOutput->schema);
            if ($buffer !== '') {
                yield new RuntimeCompletionChunk(content: $buffer);
            }
        }

        yield new RuntimeCompletionChunk(
            finishReason: $finishReason,
            usage: new Usage(
                promptTokens: $promptTokens,
                completionTokens: count($generatedTokens),
                totalTokens: $promptTokens + count($generatedTokens),
            ),
            metadata: array_filter([
                'sampledTokens' => $generatedTokens,
                'structuredMode' => $request->structuredOutput?->mode,
                'imageCount' => count($request->images),
            ], static fn(mixed $value): bool => $value !== null && $value !== []),
        );
    }

    public function snapshotState(object $context): string
    {
        $contextPointer = $this->contextPointer($context);
        $size = (int) $this->symbol('llama_state_get_size', $contextPointer);
        if ($size === 0) {
            return '';
        }

        $buffer = $this->allocate("uint8_t[{$size}]");
        $written = (int) $this->symbol('llama_state_get_data', $contextPointer, $buffer, $size);

        return $this->cString($this->cast('char *', $buffer), $written);
    }

    public function restoreState(object $context, string $bytes): void
    {
        $contextPointer = $this->contextPointer($context);
        $length = strlen($bytes);
        if ($length === 0) {
            return;
        }

        $buffer = $this->allocate("uint8_t[{$length}]");
        $this->copy($buffer, $bytes, $length);
        $read = (int) $this->symbol('llama_state_set_data', $contextPointer, $buffer, $length);
        if ($read !== $length) {
            throw new \RuntimeException('llama.cpp restored fewer state bytes than expected.');
        }
    }

    public function snapshotSequenceState(object $context, int $sequenceId): string
    {
        $contextPointer = $this->contextPointer($context);
        $size = (int) $this->symbol('llama_state_seq_get_size', $contextPointer, $sequenceId);
        if ($size === 0) {
            return '';
        }

        $buffer = $this->allocate("uint8_t[{$size}]");
        $written = (int) $this->symbol('llama_state_seq_get_data', $contextPointer, $buffer, $size, $sequenceId);

        return $this->cString($this->cast('char *', $buffer), $written);
    }

    public function restoreSequenceState(object $context, int $sequenceId, string $bytes): void
    {
        $contextPointer = $this->contextPointer($context);
        $length = strlen($bytes);
        if ($length === 0) {
            return;
        }

        $buffer = $this->allocate("uint8_t[{$length}]");
        $this->copy($buffer, $bytes, $length);
        $read = (int) $this->symbol('llama_state_seq_set_data', $contextPointer, $buffer, $length, $sequenceId);
        if ($read !== $length) {
            throw new \RuntimeException('llama.cpp restored fewer sequence-state bytes than expected.');
        }
    }

    private function readModelDescription(object $model): ?string
    {
        $buffer = $this->allocate('char[4096]');
        $written = (int) $this->symbol('llama_model_desc', $this->modelPointer($model), $buffer, 4096);

        return $written > 0 ? $this->cString($buffer) : null;
    }

    private function readModelMetadataValue(object $model, string $key): ?string
    {
        $buffer = $this->allocate('char[4096]');
        $written = (int) $this->symbol('llama_model_meta_val_str', $this->modelPointer($model), $key, $buffer, 4096);

        return $written > 0 ? $this->cString($buffer) : null;
    }

    private function readOptionalCString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        if (!($value instanceof \FFI\CData) || \FFI::isNull($value)) {
            return null;
        }

        $string = $this->cString($value);

        return $string !== '' ? $string : null;
    }

    private function resolveMaxTokens(RuntimeModelMetadata $metadata, RuntimeCompletionRequest $request): int
    {
        $requested = $request->options['maxTokens'] ?? $request->options['max_tokens'] ?? $metadata->maxTokens;

        return max(1, min($metadata->maxTokens, (int) $requested));
    }

    private function resolveGrammar(RuntimeModelMetadata $metadata, RuntimeCompletionRequest $request): ?string
    {
        $structured = $request->structuredOutput;
        if ($structured === null || !$structured->strict) {
            return null;
        }

        if ($structured->mode !== 'json_schema') {
            throw new \RuntimeException("Structured output mode '{$structured->mode}' is not supported by the native llama.cpp runtime.");
        }

        $modes = $metadata->extras['structuredOutputModes'] ?? null;
        if (is_array($modes) && !in_array('json_schema', $modes, true)) {
            throw new \RuntimeException("Strict structured output mode 'json_schema' is not supported for model {$metadata->id}.");
        }

        if (($metadata->extras['supportsStructuredOutput'] ?? !is_array($modes)) === false) {
            throw new \RuntimeException("Strict structured output mode 'json_schema' is not supported for model {$metadata->id}.");
        }

        return LlamaCppJsonGrammar::GRAMMAR;
    }

    private function evaluateTextPrompt(
        object $model,
        object $contextPointer,
        string $prompt,
        int $batchSize,
        bool $addSpecial,
        bool $parseSpecial,
    ): int {
        $tokens = $this->tokenize($model, $prompt, $addSpecial, $parseSpecial);
        if ($tokens === []) {
            return 0;
        }

        $offset = 0;
        $tokenCount = count($tokens);
        while ($offset < $tokenCount) {
            $slice = array_slice($tokens, $offset, $batchSize);
            $this->decodeTokens($contextPointer, $slice);
            $offset += count($slice);
        }

        return $tokenCount;
    }

    /**
     * @param NativeModelHandle $model
     * @param NativeContextHandle $context
     */
    private function evaluateMultimodalPrompt(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
        int $batchSize,
        bool $addSpecial,
        bool $parseSpecial,
    ): int {
        if (!$metadata->supportsVision) {
            throw new \InvalidArgumentException("Model {$metadata->id} does not support image input.");
        }

        $mtmdContext = $this->ensureMtmdContext($model, $context, $metadata, $request);
        $marker = $this->cString($this->mtmdSymbol('mtmd_default_marker'));
        $prompt = $this->prepareMultimodalPrompt($request->prompt, count($request->images), $marker);
        $text = $this->mtmdAllocate('struct mtmd_input_text[1]');
        $text[0]->text = $prompt;
        $text[0]->add_special = $addSpecial;
        $text[0]->parse_special = $parseSpecial;

        $bitmaps = $this->createMtmdBitmaps($mtmdContext, $request->images);
        $bitmapPointers = $this->mtmdAllocate('mtmd_bitmap *[' . count($bitmaps) . ']');
        foreach ($bitmaps as $index => $bitmap) {
            $bitmapPointers[$index] = $bitmap;
        }

        $chunks = $this->mtmdSymbol('mtmd_input_chunks_init');
        if (\FFI::isNull($chunks)) {
            $this->freeMtmdBitmaps($bitmaps);
            throw new \RuntimeException('Failed to initialize libmtmd input chunks.');
        }

        try {
            $result = (int) $this->mtmdSymbol(
                'mtmd_tokenize',
                $mtmdContext,
                $chunks,
                $text,
                $bitmapPointers,
                count($bitmaps),
            );
            if ($result !== 0) {
                throw new \RuntimeException("libmtmd tokenize failed with status {$result}.");
            }

            $newPast = $this->mtmdAllocate('llama_pos[1]');
            $eval = (int) $this->mtmdSymbol(
                'mtmd_helper_eval_chunks',
                $mtmdContext,
                $this->contextPointer($context),
                $chunks,
                0,
                0,
                $batchSize,
                true,
                $newPast,
            );
            if ($eval !== 0) {
                throw new \RuntimeException("libmtmd chunk evaluation failed with status {$eval}.");
            }

            return (int) $this->mtmdSymbol('mtmd_helper_get_n_tokens', $chunks);
        } finally {
            $this->mtmdSymbol('mtmd_input_chunks_free', $chunks);
            $this->freeMtmdBitmaps($bitmaps);
        }
    }

    private function prepareMultimodalPrompt(string $prompt, int $imageCount, string $marker): string
    {
        if ($imageCount === 0) {
            return $prompt;
        }

        $placeholderCount = substr_count($prompt, '[image]');
        if ($placeholderCount === $imageCount) {
            return str_replace('[image]', $marker, $prompt);
        }

        if ($placeholderCount === 0) {
            return implode("\n", array_fill(0, $imageCount, $marker)) . "\n" . $prompt;
        }

        throw new \InvalidArgumentException('Image input count does not match the number of [image] placeholders in the prompt.');
    }

    /**
     * @param RuntimeImageInput[] $images
     * @return list<mixed>
     */
    private function createMtmdBitmaps(mixed $mtmdContext, array $images): array
    {
        $bitmaps = [];

        foreach ($images as $image) {
            $length = strlen($image->bytes);
            $bytes = $this->mtmdAllocate('uint8_t[' . max(1, $length) . ']');
            if ($length > 0) {
                $this->mtmdCopy($bytes, $image->bytes, $length);
            }

            $bitmap = $this->mtmdSymbol('mtmd_helper_bitmap_init_from_buf', $mtmdContext, $bytes, $length);
            if ($bitmap === null || \FFI::isNull($bitmap)) {
                $this->freeMtmdBitmaps($bitmaps);
                throw new \RuntimeException("Failed to decode multimodal input {$image->id} with libmtmd.");
            }

            if ($image->id !== '') {
                $this->mtmdSymbol('mtmd_bitmap_set_id', $bitmap, $image->id);
            }

            $bitmaps[] = $bitmap;
        }

        return $bitmaps;
    }

    /**
     * @param list<mixed> $bitmaps
     */
    private function freeMtmdBitmaps(array $bitmaps): void
    {
        foreach ($bitmaps as $bitmap) {
            if ($bitmap !== null && !\FFI::isNull($bitmap)) {
                $this->mtmdSymbol('mtmd_bitmap_free', $bitmap);
            }
        }
    }

    /**
     * @param NativeModelHandle $model
     * @param NativeContextHandle $context
     */
    private function ensureMtmdContext(
        object $model,
        object $context,
        RuntimeModelMetadata $metadata,
        RuntimeCompletionRequest $request,
    ): mixed {
        $projectorPath = $request->options['projectorPath'] ?? $metadata->projectorPath;
        if (!is_string($projectorPath) || $projectorPath === '') {
            throw new \RuntimeException("Model {$metadata->id} requires a projectorPath to process image input.");
        }

        if (($context->mtmd ?? null) !== null && ($context->mtmdProjectorPath ?? null) === $projectorPath) {
            return $context->mtmd;
        }

        if (($context->mtmd ?? null) !== null && !\FFI::isNull($context->mtmd)) {
            $this->mtmdSymbol('mtmd_free', $context->mtmd);
            $context->mtmd = null;
        }

        $params = $this->mtmdSymbol('mtmd_context_params_default');
        $params->use_gpu = (bool) ($request->options['mmprojUseGpu'] ?? true);
        $params->print_timings = (bool) ($request->options['printTimings'] ?? false);
        $params->n_threads = max(1, (int) ($request->options['threads'] ?? $context->threads ?? 1));
        $params->warmup = (bool) ($request->options['mtmdWarmup'] ?? false);
        $marker = $this->cString($this->mtmdSymbol('mtmd_default_marker'));
        $params->media_marker = $marker;
        $params->image_marker = $marker;

        $mtmdContext = $this->mtmdSymbol('mtmd_init_from_file', $projectorPath, $this->modelPointer($model), $params);
        if ($mtmdContext === null || \FFI::isNull($mtmdContext)) {
            throw new \RuntimeException("Failed to initialize libmtmd with projector {$projectorPath}.");
        }

        $context->mtmd = $mtmdContext;
        $context->mtmdProjectorPath = $projectorPath;

        return $mtmdContext;
    }

    /**
     * @return array{vision: bool, audio: bool}|null
     */
    private function readMtmdCaps(string $projectorPath): ?array
    {
        if (!extension_loaded('FFI') || $this->mtmdLibraryPath === '') {
            return null;
        }

        try {
            $caps = $this->mtmdSymbol('mtmd_get_cap_from_file', $projectorPath);

            return [
                'vision' => (bool) $caps->inp_vision,
                'audio' => (bool) $caps->inp_audio,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function clearContextMemory(mixed $contextPointer): void
    {
        $memory = $this->symbol('llama_get_memory', $contextPointer);
        $this->symbol('llama_memory_clear', $memory, true);
    }

    /**
     * @param int[] $tokens
     */
    private function decodeTokens(mixed $contextPointer, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }

        $tokenBuffer = $this->allocate('llama_token[' . count($tokens) . ']');
        foreach ($tokens as $index => $token) {
            $tokenBuffer[$index] = (int) $token;
        }

        $batch = $this->symbol('llama_batch_get_one', $tokenBuffer, count($tokens));
        $result = (int) $this->symbol('llama_decode', $contextPointer, $batch);
        if ($result !== 0) {
            throw new \RuntimeException("llama.cpp decode failed with status {$result}.");
        }
    }

    private function createSampler(object $modelPointer, RuntimeCompletionRequest $request, ?string $grammar): mixed
    {
        $params = $this->symbol('llama_sampler_chain_default_params');
        $params->no_perf = true;
        $chain = $this->symbol('llama_sampler_chain_init', $params);
        if ($chain === null || \FFI::isNull($chain)) {
            throw new \RuntimeException('Failed to initialize llama.cpp sampler chain.');
        }

        $temperature = (float) ($request->options['temperature'] ?? 0.8);
        $topK = (int) ($request->options['topK'] ?? $request->options['top_k'] ?? 40);
        $topP = (float) ($request->options['topP'] ?? $request->options['top_p'] ?? 0.95);
        $minP = (float) ($request->options['minP'] ?? $request->options['min_p'] ?? 0.0);
        $seed = (int) ($request->options['seed'] ?? 0);
        $vocab = $this->symbol('llama_model_get_vocab', $modelPointer);

        if ($grammar !== null) {
            $grammarSampler = $this->symbol('llama_sampler_init_grammar', $vocab, $grammar, LlamaCppJsonGrammar::ROOT_RULE);
            if ($grammarSampler === null || \FFI::isNull($grammarSampler)) {
                throw new \RuntimeException('Failed to initialize llama.cpp grammar sampler.');
            }
            $this->symbol('llama_sampler_chain_add', $chain, $grammarSampler);
        }

        if ($topK > 0) {
            $this->symbol('llama_sampler_chain_add', $chain, $this->symbol('llama_sampler_init_top_k', $topK));
        }

        if ($topP > 0.0 && $topP < 1.0) {
            $this->symbol('llama_sampler_chain_add', $chain, $this->symbol('llama_sampler_init_top_p', $topP, 1));
        }

        if ($minP > 0.0) {
            $this->symbol('llama_sampler_chain_add', $chain, $this->symbol('llama_sampler_init_min_p', $minP, 1));
        }

        if ($temperature > 0.0) {
            $this->symbol('llama_sampler_chain_add', $chain, $this->symbol('llama_sampler_init_temp', $temperature));
            $this->symbol('llama_sampler_chain_add', $chain, $this->symbol('llama_sampler_init_dist', $seed));
        } else {
            $this->symbol('llama_sampler_chain_add', $chain, $this->symbol('llama_sampler_init_greedy'));
        }

        return $chain;
    }

    /**
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
    }

    private function modelPointer(object $model): mixed
    {
        return $model->pointer ?? $model;
    }

    private function contextPointer(object $context): mixed
    {
        return $context->pointer ?? $context;
    }

    /**
     * @return mixed
     */
    private function symbol(string $symbol, mixed ...$arguments): mixed
    {
        $ffi = $this->ffi();

        return $ffi->{$symbol}(...$arguments);
    }

    /**
     * @return mixed
     */
    private function allocate(string $type): mixed
    {
        $ffi = $this->ffi();

        return $ffi->{'new'}($type);
    }

    /**
     * @return mixed
     */
    private function cast(string $type, mixed $value): mixed
    {
        $ffi = $this->ffi();

        return $ffi->{'cast'}($type, $value);
    }

    private function cString(mixed $value, ?int $length = null): string
    {
        if ($length === null) {
            return \FFI::string($value);
        }

        if ($length < 0) {
            throw new \RuntimeException('FFI string length cannot be negative.');
        }

        return \FFI::string($value, $length);
    }

    private function copy(mixed $target, string $source, int $length): void
    {
        if ($length < 0) {
            throw new \RuntimeException('FFI copy length cannot be negative.');
        }

        $ffi = $this->ffi();

        $ffi->{'memcpy'}($target, $source, $length);
    }

    private function ffi(): \FFI
    {
        if ($this->ffi !== null) {
            return $this->ffi;
        }

        if (!extension_loaded('FFI')) {
            throw new \RuntimeException('PHP FFI extension is not available.');
        }

        $this->ffi = \FFI::cdef(self::cdef(), $this->libraryPath);

        return $this->ffi;
    }

    private function mtmdFfi(): \FFI
    {
        if ($this->mtmdFfi !== null) {
            return $this->mtmdFfi;
        }

        if (!extension_loaded('FFI')) {
            throw new \RuntimeException('PHP FFI extension is not available.');
        }

        $this->mtmdFfi = \FFI::cdef(self::mtmdCdef(), $this->mtmdLibraryPath);

        return $this->mtmdFfi;
    }

    /**
     * @return mixed
     */
    private function mtmdSymbol(string $symbol, mixed ...$arguments): mixed
    {
        $ffi = $this->mtmdFfi();

        return $ffi->{$symbol}(...$arguments);
    }

    /**
     * @return mixed
     */
    private function mtmdAllocate(string $type): mixed
    {
        $ffi = $this->mtmdFfi();

        return $ffi->{'new'}($type);
    }

    private function mtmdCopy(mixed $target, string $source, int $length): void
    {
        if ($length < 0) {
            throw new \RuntimeException('FFI copy length cannot be negative.');
        }

        $ffi = $this->mtmdFfi();

        $ffi->{'memcpy'}($target, $source, $length);
    }

    private static function cdef(): string
    {
        return <<<'CDEF'
typedef unsigned char bool;
typedef int int32_t;
typedef unsigned int uint32_t;
typedef unsigned long long uint64_t;
typedef long long int64_t;
typedef unsigned char uint8_t;
typedef unsigned long size_t;
typedef int32_t llama_token;
typedef int32_t llama_pos;
typedef int32_t llama_seq_id;
typedef struct llama_memory_i * llama_memory_t;

struct ggml_backend_dev;
typedef struct ggml_backend_dev * ggml_backend_dev_t;

struct llama_model;
struct llama_context;
struct llama_vocab;
struct llama_sampler;
struct llama_model_tensor_buft_override;
struct llama_model_kv_override;
struct llama_sampler_seq_config;

typedef struct llama_batch {
    int32_t n_tokens;
    llama_token * token;
    float * embd;
    llama_pos * pos;
    int32_t * n_seq_id;
    llama_seq_id ** seq_id;
    int8_t * logits;
} llama_batch;

typedef struct llama_sampler_chain_params {
    bool no_perf;
} llama_sampler_chain_params;

struct llama_model_params {
    ggml_backend_dev_t * devices;
    const struct llama_model_tensor_buft_override * tensor_buft_overrides;
    int32_t n_gpu_layers;
    int32_t split_mode;
    int32_t main_gpu;
    const float * tensor_split;
    void * progress_callback;
    void * progress_callback_user_data;
    const struct llama_model_kv_override * kv_overrides;
    bool vocab_only;
    bool use_mmap;
    bool use_direct_io;
    bool use_mlock;
    bool check_tensors;
    bool use_extra_bufts;
    bool no_host;
    bool no_alloc;
};

struct llama_context_params {
    uint32_t n_ctx;
    uint32_t n_batch;
    uint32_t n_ubatch;
    uint32_t n_seq_max;
    int32_t n_threads;
    int32_t n_threads_batch;
    int32_t rope_scaling_type;
    int32_t pooling_type;
    int32_t attention_type;
    int32_t flash_attn_type;
    float rope_freq_base;
    float rope_freq_scale;
    float yarn_ext_factor;
    float yarn_attn_factor;
    float yarn_beta_fast;
    float yarn_beta_slow;
    uint32_t yarn_orig_ctx;
    float defrag_thold;
    void * cb_eval;
    void * cb_eval_user_data;
    int32_t type_k;
    int32_t type_v;
    void * abort_callback;
    void * abort_callback_data;
    bool embeddings;
    bool offload_kqv;
    bool no_perf;
    bool op_offload;
    bool swa_full;
    bool kv_unified;
    struct llama_sampler_seq_config * samplers;
    size_t n_samplers;
};

struct llama_model_params llama_model_default_params(void);
struct llama_context_params llama_context_default_params(void);
struct llama_sampler_chain_params llama_sampler_chain_default_params(void);
void llama_backend_init(void);
struct llama_model * llama_model_load_from_file(const char * path_model, struct llama_model_params params);
void llama_model_free(struct llama_model * model);
struct llama_context * llama_init_from_model(struct llama_model * model, struct llama_context_params params);
void llama_free(struct llama_context * ctx);
uint32_t llama_n_ctx(const struct llama_context * ctx);
int32_t llama_model_n_ctx_train(const struct llama_model * model);
const struct llama_vocab * llama_model_get_vocab(const struct llama_model * model);
int32_t llama_vocab_n_tokens(const struct llama_vocab * vocab);
int32_t llama_model_desc(const struct llama_model * model, char * buf, size_t buf_size);
int32_t llama_model_meta_val_str(const struct llama_model * model, const char * key, char * buf, size_t buf_size);
const char * llama_model_chat_template(const struct llama_model * model, const char * name);
int32_t llama_tokenize(const struct llama_vocab * vocab, const char * text, int32_t text_len, llama_token * tokens, int32_t n_tokens_max, bool add_special, bool parse_special);
int32_t llama_detokenize(const struct llama_vocab * vocab, const llama_token * tokens, int32_t n_tokens, char * text, int32_t text_len_max, bool remove_special, bool unparse_special);
llama_memory_t llama_get_memory(const struct llama_context * ctx);
void llama_memory_clear(llama_memory_t mem, bool data);
struct llama_batch llama_batch_get_one(llama_token * tokens, int32_t n_tokens);
int32_t llama_decode(struct llama_context * ctx, struct llama_batch batch);
bool llama_vocab_is_eog(const struct llama_vocab * vocab, llama_token token);
struct llama_sampler * llama_sampler_chain_init(struct llama_sampler_chain_params params);
void llama_sampler_chain_add(struct llama_sampler * chain, struct llama_sampler * smpl);
struct llama_sampler * llama_sampler_init_greedy(void);
struct llama_sampler * llama_sampler_init_dist(uint32_t seed);
struct llama_sampler * llama_sampler_init_top_k(int32_t k);
struct llama_sampler * llama_sampler_init_top_p(float p, size_t min_keep);
struct llama_sampler * llama_sampler_init_min_p(float p, size_t min_keep);
struct llama_sampler * llama_sampler_init_temp(float t);
struct llama_sampler * llama_sampler_init_grammar(const struct llama_vocab * vocab, const char * grammar_str, const char * grammar_root);
llama_token llama_sampler_sample(struct llama_sampler * smpl, struct llama_context * ctx, int32_t idx);
void llama_sampler_free(struct llama_sampler * smpl);
size_t llama_state_get_size(struct llama_context * ctx);
size_t llama_state_get_data(struct llama_context * ctx, uint8_t * dst, size_t size);
size_t llama_state_set_data(struct llama_context * ctx, const uint8_t * src, size_t size);
size_t llama_state_seq_get_size(struct llama_context * ctx, llama_seq_id seq_id);
size_t llama_state_seq_get_data(struct llama_context * ctx, uint8_t * dst, size_t size, llama_seq_id seq_id);
size_t llama_state_seq_set_data(struct llama_context * ctx, const uint8_t * src, size_t size, llama_seq_id dest_seq_id);
CDEF;
    }

    private static function mtmdCdef(): string
    {
        return <<<'CDEF'
typedef unsigned char bool;
typedef int int32_t;
typedef unsigned int uint32_t;
typedef unsigned char uint8_t;
typedef unsigned long size_t;
typedef int32_t llama_token;
typedef int32_t llama_pos;

struct llama_model;
struct llama_context;
struct mtmd_context;
struct mtmd_bitmap;
struct mtmd_input_chunks;

typedef struct mtmd_context mtmd_context;
typedef struct mtmd_bitmap mtmd_bitmap;
typedef struct mtmd_input_chunks mtmd_input_chunks;

struct mtmd_input_text {
    const char * text;
    bool add_special;
    bool parse_special;
};

struct mtmd_context_params {
    bool use_gpu;
    bool print_timings;
    int n_threads;
    const char * image_marker;
    const char * media_marker;
    int32_t flash_attn_type;
    bool warmup;
    int image_min_tokens;
    int image_max_tokens;
    void * cb_eval;
    void * cb_eval_user_data;
};

struct mtmd_caps {
    bool inp_vision;
    bool inp_audio;
};

const char * mtmd_default_marker(void);
struct mtmd_context_params mtmd_context_params_default(void);
mtmd_context * mtmd_init_from_file(const char * mmproj_fname, const struct llama_model * text_model, const struct mtmd_context_params ctx_params);
void mtmd_free(mtmd_context * ctx);
mtmd_bitmap * mtmd_helper_bitmap_init_from_buf(mtmd_context * ctx, const unsigned char * buf, size_t len);
void mtmd_bitmap_free(mtmd_bitmap * bitmap);
void mtmd_bitmap_set_id(mtmd_bitmap * bitmap, const char * id);
mtmd_input_chunks * mtmd_input_chunks_init(void);
void mtmd_input_chunks_free(mtmd_input_chunks * chunks);
int32_t mtmd_tokenize(mtmd_context * ctx, mtmd_input_chunks * output, const struct mtmd_input_text * text, const mtmd_bitmap ** bitmaps, size_t n_bitmaps);
size_t mtmd_helper_get_n_tokens(const mtmd_input_chunks * chunks);
int32_t mtmd_helper_eval_chunks(mtmd_context * ctx, struct llama_context * lctx, const mtmd_input_chunks * chunks, llama_pos n_past, int32_t seq_id, int32_t n_batch, bool logits_last, llama_pos * new_n_past);
struct mtmd_caps mtmd_get_cap_from_file(const char * mmproj_fname);
CDEF;
    }
}