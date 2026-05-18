#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

DEFAULT_RUNTIME_ROOT="${PHP_AGENTS_RUNTIME_ROOT:-$HOME/.cache/php-agents}"
DEFAULT_HF_REPO="bartowski/Qwen2.5-1.5B-Instruct-GGUF"
DEFAULT_HF_FILE="Qwen2.5-1.5B-Instruct-Q4_K_M.gguf"

install_method="source"
runtime_root="$DEFAULT_RUNTIME_ROOT"
llama_repo="https://github.com/ggml-org/llama.cpp.git"
llama_dir=""
model_dir=""
env_file="$PROJECT_ROOT/.llama-cpp.env"
hf_repo="$DEFAULT_HF_REPO"
hf_file="$DEFAULT_HF_FILE"
model_path=""
lib_path=""
mtmd_lib_path=""
ollama_base_url="${OLLAMA_BASE_URL:-http://localhost:11434/v1}"
ollama_model="${OLLAMA_MODEL:-qwen2.5:1.5b}"
num_ctx="4096"
threads="4"
force=0
skip_model_download=0

if [[ -t 1 ]]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    NC='\033[0m'
else
    GREEN='' RED='' YELLOW='' BLUE='' NC=''
fi

info()  { echo -e "${BLUE}[setup]${NC} $*"; }
ok()    { echo -e "${GREEN}[ok]${NC} $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
fail()  { echo -e "${RED}[fail]${NC} $*"; exit 1; }

usage() {
    cat <<EOF
Usage: $0 [options]

Build or locate llama.cpp, download a default GGUF model unless told not to, and write a reusable env file.

Default GGUF:
    repo: $DEFAULT_HF_REPO
    file: $DEFAULT_HF_FILE

Options:
  --install-method <source|brew|skip>   Installation mode for llama.cpp (default: source)
  --runtime-root <path>                 Root directory for runtime assets (default: $DEFAULT_RUNTIME_ROOT)
  --llama-dir <path>                    Override llama.cpp source directory
  --env-file <path>                     Env file to write (default: $env_file)
  --lib-path <path>                     Use an existing libllama shared library
  --mtmd-lib-path <path>                Use an existing libmtmd shared library
    --model-path <path>                   Use an existing GGUF model path
    --hf-repo <repo>                      Hugging Face repo or full model URL containing a GGUF artifact
  --hf-file <file>                      GGUF file path inside the Hugging Face repo
  --ollama-base-url <url>               Remote Ollama/OpenAI-compatible base URL
    --ollama-model <name>                 Remote model name for comparison tests
  --num-ctx <int>                       Default context window to persist to the env file
  --threads <int>                       Default thread count to persist to the env file
    --skip-model-download                 Only set up llama.cpp and env vars, do not fetch a GGUF
  --force                               Rebuild and redownload when possible
  --help                                Show this help text

Examples:
    $0
    $0 --hf-repo your-org/your-model-GGUF --hf-file model-q4_k_m.gguf
    $0 --ollama-model ollama/qwen2.5:1.5b
  $0 --install-method skip --lib-path /opt/lib/libllama.dylib --model-path /models/qwen.gguf
EOF
}

normalize_hugging_face_repo() {
        local value="$1"

        value="${value#https://huggingface.co/}"
        value="${value#http://huggingface.co/}"
        value="${value%/}"

        printf '%s' "$value"
}

normalize_ollama_model() {
        local value="$1"

        value="${value#ollama/}"

        printf '%s' "$value"
}

require_command() {
    local command_name="$1"

    if ! command -v "$command_name" >/dev/null 2>&1; then
        fail "Required command not found: $command_name"
    fi
}

find_first_matching_file() {
    local search_root="$1"
    shift

    while IFS= read -r match; do
        echo "$match"
        return 0
    done < <(find "$search_root" -type f \( "$@" \) 2>/dev/null | sort)

    return 1
}

find_llama_library() {
    local search_root="$1"
    local exact_match=""

    exact_match="$(find_first_matching_file "$search_root" -name 'libllama.dylib' -o -name 'libllama.so' -o -name 'libllama.0.dylib' -o -name 'libllama.0.so')" || true
    if [[ -n "$exact_match" ]]; then
        printf '%s' "$exact_match"
        return 0
    fi

    exact_match="$(find_first_matching_file "$search_root" -name 'libllama.*.dylib' -o -name 'libllama.*.so')" || true
    if [[ -n "$exact_match" ]]; then
        printf '%s' "$exact_match"
        return 0
    fi

    return 1
}

download_model() {
    if [[ -n "$model_path" ]]; then
        [[ -f "$model_path" ]] || fail "Model path does not exist: $model_path"
        ok "Using existing GGUF model: $model_path"
        return
    fi

    if [[ $skip_model_download -eq 1 ]]; then
        warn "No model was provided. The env file will be written without LLAMA_CPP_MODEL_PATH."
        return
    fi

    [[ -n "$hf_repo" && -n "$hf_file" ]] || fail "Both --hf-repo and --hf-file are required to download a GGUF artifact."

    require_command curl

    mkdir -p "$model_dir"

    hf_repo="$(normalize_hugging_face_repo "$hf_repo")"

    local destination="$model_dir/$(basename "$hf_file")"
    local partial_destination="$destination.part"
    local url="https://huggingface.co/${hf_repo}/resolve/main/${hf_file}?download=true"

    if [[ -f "$destination" && $force -ne 1 ]]; then
        model_path="$destination"
        ok "Reusing downloaded GGUF: $destination"
        return
    fi

    if [[ $force -eq 1 ]]; then
        rm -f "$partial_destination"
    fi

    if [[ -f "$partial_destination" ]]; then
        info "Resuming partial GGUF download from $partial_destination"
    else
        info "Downloading GGUF from Hugging Face: ${hf_repo}/${hf_file}"
    fi

    curl \
        -L \
        --fail \
        --retry 5 \
        --retry-delay 5 \
        --retry-all-errors \
        --continue-at - \
        --output "$partial_destination" \
        "$url"

    mv "$partial_destination" "$destination"
    model_path="$destination"
    ok "Downloaded GGUF to $destination"
}

build_from_source() {
    require_command git
    require_command cmake

    mkdir -p "$runtime_root"

    if [[ ! -d "$llama_dir/.git" ]]; then
        info "Cloning llama.cpp into $llama_dir"
        git clone --depth 1 "$llama_repo" "$llama_dir"
    else
        info "Updating llama.cpp checkout"
        git -C "$llama_dir" pull --ff-only
    fi

    info "Building llama.cpp shared libraries"
    cmake -S "$llama_dir" -B "$llama_dir/build" -DBUILD_SHARED_LIBS=ON -DCMAKE_BUILD_TYPE=Release
    cmake --build "$llama_dir/build" --config Release -j

    lib_path="$(find_llama_library "$llama_dir/build")" || fail "Unable to locate libllama after building llama.cpp"
    mtmd_lib_path="$(find_first_matching_file "$llama_dir/build" -name 'libmtmd*.dylib' -o -name 'libmtmd*.so')" || true
}

install_from_brew() {
    require_command brew

    info "Installing llama.cpp via Homebrew"
    brew install llama.cpp

    local brew_prefix
    brew_prefix="$(brew --prefix llama.cpp)"

    lib_path="$(find_llama_library "$brew_prefix")" || true
    mtmd_lib_path="$(find_first_matching_file "$brew_prefix" -name 'libmtmd*.dylib' -o -name 'libmtmd*.so')" || true

    if [[ -z "$lib_path" ]]; then
        fail "Homebrew installed llama.cpp CLI tools, but no libllama shared library was found. Use --install-method source for php-agents' native FFI runtime."
    fi
}

write_env_file() {
    local cache_dir="$runtime_root/llama-cache"
    local llama_lib_dir

    llama_lib_dir="$(dirname "$lib_path")"

    mkdir -p "$(dirname "$env_file")"

    cat > "$env_file" <<EOF
# Generated by scripts/setup-llama-cpp.sh
export PHP_AGENTS_RUNTIME_ROOT="$runtime_root"
export LLAMA_CACHE="$cache_dir"
export DYLD_LIBRARY_PATH="$llama_lib_dir:${DYLD_LIBRARY_PATH:-}"
export LLAMA_CPP_LIB_PATH="$lib_path"
export LLAMA_CPP_NUM_CTX="$num_ctx"
export LLAMA_CPP_THREADS="$threads"
export OLLAMA_BASE_URL="$ollama_base_url"
EOF

    if [[ -n "$mtmd_lib_path" ]]; then
        cat >> "$env_file" <<EOF
# Optional: enable only if you want vision/libmtmd coverage and the library loads cleanly on your host.
# export LLAMA_CPP_MTMD_LIB_PATH="$mtmd_lib_path"
EOF
    fi

    if [[ -n "$model_path" ]]; then
        cat >> "$env_file" <<EOF
export LLAMA_CPP_MODEL_PATH="$model_path"
EOF
    else
        cat >> "$env_file" <<'EOF'
# export LLAMA_CPP_MODEL_PATH="/absolute/path/to/model.gguf"
EOF
    fi

    if [[ -n "$ollama_model" ]]; then
        cat >> "$env_file" <<EOF
export OLLAMA_MODEL="$ollama_model"
EOF
    else
        cat >> "$env_file" <<'EOF'
# export OLLAMA_MODEL="your-ollama-model-name"
EOF
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --install-method)
            install_method="$2"
            shift 2
            ;;
        --runtime-root)
            runtime_root="$2"
            shift 2
            ;;
        --llama-dir)
            llama_dir="$2"
            shift 2
            ;;
        --env-file)
            env_file="$2"
            shift 2
            ;;
        --lib-path)
            lib_path="$2"
            shift 2
            ;;
        --mtmd-lib-path)
            mtmd_lib_path="$2"
            shift 2
            ;;
        --model-path)
            model_path="$2"
            shift 2
            ;;
        --hf-repo)
            hf_repo="$2"
            shift 2
            ;;
        --hf-file)
            hf_file="$2"
            shift 2
            ;;
        --ollama-base-url)
            ollama_base_url="$2"
            shift 2
            ;;
        --ollama-model)
            ollama_model="$2"
            shift 2
            ;;
        --num-ctx)
            num_ctx="$2"
            shift 2
            ;;
        --threads)
            threads="$2"
            shift 2
            ;;
        --skip-model-download)
            skip_model_download=1
            hf_repo=""
            hf_file=""
            shift
            ;;
        --force)
            force=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            fail "Unknown argument: $1"
            ;;
    esac
done

llama_dir="${llama_dir:-$runtime_root/llama.cpp}"
model_dir="${model_dir:-$runtime_root/models}"
ollama_model="$(normalize_ollama_model "$ollama_model")"

info "php-agents llama.cpp setup"
info "Install method: $install_method"
if [[ $skip_model_download -eq 0 && -z "$model_path" ]]; then
    info "Default GGUF: ${hf_repo}/${hf_file}"
fi

case "$install_method" in
    source)
        build_from_source
        ;;
    brew)
        install_from_brew
        ;;
    skip)
        [[ -n "$lib_path" ]] || fail "--install-method skip requires --lib-path"
        ;;
    *)
        fail "Unsupported install method: $install_method"
        ;;
esac

[[ -f "$lib_path" ]] || fail "libllama shared library not found: $lib_path"

download_model
write_env_file

ok "Environment file written to $env_file"
echo ""
echo "Next steps:"
echo "  1. source \"$env_file\""
echo "  2. composer test:llama-cpp-runtime"
echo ""
echo "Notes:"
echo "  - php-agents' native runtime needs a shared libllama build. The default source build handles that."
echo "  - Hugging Face's llama.cpp docs support -hf downloads plus LLAMA_CACHE. This script writes LLAMA_CACHE but downloads GGUFs to a deterministic local path so LLAMA_CPP_MODEL_PATH stays explicit."
echo "  - OLLAMA_MODEL defaults to qwen2.5:1.5b and also accepts Coqui-style identifiers such as ollama/qwen2.5:1.5b."
echo "  - The Qwen/Qwen3-Coder-30B-A3B-Instruct base repo is not itself a GGUF artifact. For local llama.cpp use a GGUF quantization repo/file."