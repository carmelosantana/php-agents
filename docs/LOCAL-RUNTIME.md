# Local Runtime

php-agents exposes a public local-runtime seam so providers can run directly against a local model runtime without going through an HTTP sidecar.

The first concrete implementation is the native llama.cpp runtime under `src/Runtime/LlamaCpp/LlamaCppNativeRuntime.php`.

## Current Scope

- Native model and context open-close through PHP FFI
- Tokenization and detokenization
- Full and per-sequence state snapshot or restore
- Direct text generation and streaming with llama.cpp samplers
- Strict structured output enforcement using a JSON grammar plus post-generation schema validation
- Image input through `libmtmd` when a projector file is configured

## Requirements

- PHP with the `FFI` extension enabled
- A shared llama.cpp library exposed through `LLAMA_CPP_LIB_PATH`
- A GGUF model exposed through `LLAMA_CPP_MODEL_PATH`
- For image input: a shared `libmtmd` build and a projector GGUF

If `LLAMA_CPP_MTMD_LIB_PATH` is not set, php-agents will try to load `libmtmd` from the same path used for `LLAMA_CPP_LIB_PATH`.

## Do users need to build llama.cpp?

For php-agents' native llama.cpp runtime, usually yes.

- If a user wants the native FFI runtime, they need a shared `libllama` build or package that exposes `libllama` on disk.
- If a user only wants the llama.cpp CLI for ad hoc prompts, a package install such as Homebrew is enough.
- The new setup script defaults to a source build because that is the most reliable way to guarantee `libllama` exists for PHP FFI.

This distinction matters because the CLI alone is not sufficient for php-agents' native runtime. The native runtime loads `libllama` directly.

## Setup llama.cpp

For php-agents, the recommended setup is a local llama.cpp build with shared libraries enabled so PHP FFI can load `libllama` directly.

### Recommended source build

```bash
git clone https://github.com/ggml-org/llama.cpp.git
cd llama.cpp
cmake -S . -B build -DBUILD_SHARED_LIBS=ON -DCMAKE_BUILD_TYPE=Release
cmake --build build --config Release -j
```

Locate the shared libraries after the build:

```bash
find build \( -name 'libllama*.dylib' -o -name 'libmtmd*.dylib' -o -name 'libllama*.so' -o -name 'libmtmd*.so' \)
```

Then export the runtime environment variables:

```bash
export LLAMA_CPP_LIB_PATH="/path/to/libllama.dylib"
export LLAMA_CPP_MTMD_LIB_PATH="/path/to/libmtmd.dylib"   # optional, needed for image input if separate
export LLAMA_CPP_MODEL_PATH="/path/to/model.gguf"
```

### One-command setup

php-agents now ships a setup script that can build llama.cpp, download a default Qwen2.5 1.5B GGUF artifact, and write a reusable env file:

```bash
composer setup:llama-cpp
source ./.llama-cpp.env
```

By default the script:

- builds llama.cpp from source with `BUILD_SHARED_LIBS=ON`
- writes `.llama-cpp.env`
- keeps that env file local to your machine; it is generated runtime state and should not be committed
- persists `LLAMA_CACHE` to a user cache directory
- keeps the GGUF path explicit so `LLAMA_CPP_MODEL_PATH` is deterministic
- defaults `OLLAMA_BASE_URL` to `http://localhost:11434/v1`
- defaults `OLLAMA_MODEL` to `qwen2.5:1.5b`
- defaults the local GGUF to `bartowski/Qwen2.5-1.5B-Instruct-GGUF` with `Qwen2.5-1.5B-Instruct-Q4_K_M.gguf`

It also accepts the Coqui/OpenClaw-style model identifier `ollama/qwen2.5:1.5b` and normalizes it to the raw Ollama model name before running the comparison.

Override the default GGUF when you need a different quantization:

```bash
composer setup:llama-cpp -- --hf-repo your-org/your-model-GGUF --hf-file model-q4_k_m.gguf
source ./.llama-cpp.env
```

If you only want the shared library build and do not want a model download yet:

```bash
composer setup:llama-cpp -- --skip-model-download
source ./.llama-cpp.env
```

If you already have a local build, skip the build step and point the script at existing files:

```bash
composer setup:llama-cpp -- --install-method skip --lib-path /path/to/libllama.dylib --model-path /path/to/model.gguf
source ./.llama-cpp.env
```

### CLI-first setup

If you only want the llama.cpp binaries first, install them through the package route documented by llama.cpp, for example Homebrew on macOS, then switch to a shared-library build when you want to exercise php-agents' native FFI path.

## Install a model to test

There are two practical paths.

### Option A: download a GGUF directly with llama.cpp

```bash
llama-cli -hf ggml-org/gemma-3-1b-it-GGUF
```

This is the fastest way to get a compatible GGUF on disk. After the download completes, point `LLAMA_CPP_MODEL_PATH` at the GGUF file.

The Hugging Face GGUF llama.cpp guide also highlights that `llama.cpp` can cache downloads via `LLAMA_CACHE`. php-agents' setup script keeps `LLAMA_CACHE` available but downloads to a deterministic file path because the native runtime needs an explicit `LLAMA_CPP_MODEL_PATH`.

### Option B: download a GGUF manually

Download a `.gguf` file from Hugging Face or another compatible source, then export:

```bash
export LLAMA_CPP_MODEL_PATH="/path/to/your-model.gguf"
```

The `https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct` page is the base Transformers/Safetensors model card, not the GGUF artifact itself. For local llama.cpp usage you need a GGUF quantization repo and file, then point the setup script at that GGUF artifact.

For the default Qwen2.5 1.5B setup, the shipped `.llama-cpp.env` uses a `4096` context window because it provides a stable local integration footprint on typical developer machines.

So the recommended split for this exact setup is:

- remote comparison model: `ollama/qwen2.5:1.5b`
- local llama.cpp model: `bartowski/Qwen2.5-1.5B-Instruct-GGUF` with `Qwen2.5-1.5B-Instruct-Q4_K_M.gguf`, or another compatible GGUF provided as `--model-path` or `--hf-repo` plus `--hf-file`

For image-capable models, also provide the matching projector file:

```bash
export LLAMA_CPP_MMPROJ_PATH="/path/to/mmproj.gguf"
export LLAMA_CPP_VISION_IMAGE_PATH="/path/to/sample-image.png"
```

### Run the guarded integration tests

```bash
./vendor/bin/pest tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php
```

These tests automatically skip when the required environment variables are not set.

## One-command full test

After sourcing `.llama-cpp.env`, run:

```bash
composer test:llama-cpp-runtime
```

That wrapper does four things:

- runs the guarded native integration suite
- runs the native runtime benchmark
- runs the native warm-handle cache benchmark
- if `OLLAMA_MODEL` is set, runs one consolidated Ollama comparison suite that reports both provider-surface behavior through php-agents' `OllamaProvider` and raw-parity behavior through Ollama `POST /api/generate`

If you only want the cache benchmark directly, run:

```bash
composer benchmark:llama-cpp-cache
```

If you only want the consolidated Ollama comparison directly, run:

```bash
composer compare:llama-cpp-vs-ollama
```

That comparison command prints two sections:

- `providerSurface` for end-to-end php-agents behavior through `OllamaProvider`
- `rawParity` for same-prompt comparison through Ollama `POST /api/generate` with `raw: true`

If you only want the local runtime checks, skip the remote comparison:

```bash
composer test:llama-cpp-runtime -- --skip-remote
```

## Test Ollama vs llama.cpp with the same exact model

The only defensible comparison is to run both stacks against the same GGUF file.

### 1. Use one shared GGUF file

Pick a local GGUF, for example `models/gemma-3-1b-it.gguf`.

### 2. Run llama.cpp against that file

```bash
llama-server -m models/gemma-3-1b-it.gguf --port 8080
```

### 3. Import that exact GGUF into Ollama

Create a `Modelfile` next to the GGUF:

```text
FROM ./gemma-3-1b-it.gguf
```

Then build the Ollama model:

```bash
ollama create gemma-3-1b-direct -f Modelfile
```

If your remote endpoint is already hosting a model behind `http://localhost:11434/v1`, you can also point `.llama-cpp.env` at that endpoint with `OLLAMA_BASE_URL` and `OLLAMA_MODEL` and use `composer test:llama-cpp-runtime` as a functional integration check. That is a provider-surface comparison, not a strict raw-prompt parity check.

### 4. Compare with raw prompts and matched sampling settings

Use Ollama `POST /api/generate` with `raw: true` so Ollama does not apply an extra template layer. Match the same sampler settings on both sides:

- `seed`
- `temperature`
- `top_k`
- `top_p`
- `min_p`
- `num_predict`
- `num_ctx`

Example Ollama request:

```bash
curl http://localhost:11434/api/generate -d '{
    "model": "gemma-3-1b-direct",
    "prompt": "Explain local inference in one sentence.",
    "raw": true,
    "stream": false,
    "options": {
        "seed": 123,
        "temperature": 0,
        "top_k": 40,
        "top_p": 0.95,
        "min_p": 0.0,
        "num_predict": 64,
        "num_ctx": 4096
    }
}'
```

Example llama.cpp CLI request:

```bash
llama-cli \
    -m models/gemma-3-1b-it.gguf \
    -p "Explain local inference in one sentence." \
    --seed 123 \
    --temp 0 \
    --top-k 40 \
    --top-p 0.95 \
    --min-p 0.0 \
    -n 64 \
    -c 4096
```

### 5. Compare the right metrics

Compare these, not just the text:

- prompt token count
- completion token count
- prompt evaluation time
- generation time
- tokens per second
- whether the same stop condition was hit

### Notes on exactness

- Same GGUF file is mandatory.
- `raw: true` on Ollama is mandatory if you want prompt-level parity.
- Do not add `SYSTEM`, `TEMPLATE`, or `PARAMETER` lines in the Ollama `Modelfile` unless you also mirror them on the llama.cpp side.
- Small output differences can still happen if backend defaults differ, but matching seed and sampler settings removes most avoidable drift.

## Integration Matrix

| Capability | Required Environment | Covered By |
| ----------- | ---------------------- | ------------ |
| Open model + tokenize | `LLAMA_CPP_LIB_PATH`, `LLAMA_CPP_MODEL_PATH` | `tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php` |
| Text generation | `LLAMA_CPP_LIB_PATH`, `LLAMA_CPP_MODEL_PATH` | `tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php` |
| Strict structured JSON | `LLAMA_CPP_LIB_PATH`, `LLAMA_CPP_MODEL_PATH` | `tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php` |
| Vision input via `libmtmd` | `LLAMA_CPP_LIB_PATH`, `LLAMA_CPP_MODEL_PATH`, `LLAMA_CPP_MMPROJ_PATH`, `LLAMA_CPP_VISION_IMAGE_PATH` | `tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php` |
| Optional separate mtmd library | `LLAMA_CPP_MTMD_LIB_PATH` | `tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php` |

Run the guarded integration suite with:

```bash
./vendor/bin/pest tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php
```

Tests auto-skip when the required environment is not present.

## Benchmark Script

Use the native benchmark entrypoint to collect direct-runtime timings:

```bash
composer benchmark:llama-cpp-runtime
```

The script reads the same environment variables as the integration suite and prints a JSON summary with timings for:

- open
- tokenize
- generate
- strict structured output
- optional vision generation

## Example

```php
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\FfiLlamaCppNativeApi;
use CarmeloSantana\PHPAgents\Runtime\LlamaCpp\LlamaCppNativeRuntime;
use CarmeloSantana\PHPAgents\Runtime\RuntimeCompletionRequest;
use CarmeloSantana\PHPAgents\Runtime\RuntimeModelMetadata;

$runtime = new LlamaCppNativeRuntime(
    new FfiLlamaCppNativeApi(getenv('LLAMA_CPP_LIB_PATH')),
    [new RuntimeModelMetadata(
        id: 'local-llama',
        name: 'Local Llama',
        path: getenv('LLAMA_CPP_MODEL_PATH'),
        extras: [
            'supportsStructuredOutput' => true,
            'structuredOutputModes' => ['json_schema'],
        ],
    )],
    ['threads' => 2, 'numCtx' => 4096],
);

$handle = $runtime->open('local-llama');
$result = $handle->generate(new RuntimeCompletionRequest(
    prompt: 'Write one short sentence about direct local inference.',
    options: ['temperature' => 0.0, 'maxTokens' => 32],
));

echo $result->content;
```
