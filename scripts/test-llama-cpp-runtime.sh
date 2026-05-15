#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

env_file="$PROJECT_ROOT/.llama-cpp.env"
do_install=0
skip_remote=0

if [[ -t 1 ]]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    NC='\033[0m'
else
    GREEN='' RED='' YELLOW='' BLUE='' NC=''
fi

info()  { echo -e "${BLUE}[test]${NC} $*"; }
ok()    { echo -e "${GREEN}[ok]${NC} $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
fail()  { echo -e "${RED}[fail]${NC} $*"; exit 1; }

usage() {
    cat <<EOF
Usage: $0 [options]

Run the native llama.cpp integration slice, the runtime benchmark, and an optional
Ollama/OpenAI-compatible comparison using php-agents' provider surface.

Options:
  --env-file <path>       Env file to source (default: $env_file)
  --install               Run composer install first
  --skip-remote           Skip the Ollama/OpenAI-compatible comparison step
  --help                  Show this help text
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)
            env_file="$2"
            shift 2
            ;;
        --install)
            do_install=1
            shift
            ;;
        --skip-remote)
            skip_remote=1
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

cd "$PROJECT_ROOT"

[[ -f "$env_file" ]] || fail "Env file not found: $env_file. Run scripts/setup-llama-cpp.sh first or pass --env-file."

set -a
# shellcheck disable=SC1090
source "$env_file"
set +a

[[ -n "${LLAMA_CPP_LIB_PATH:-}" ]] || fail "LLAMA_CPP_LIB_PATH is not set"
[[ -n "${LLAMA_CPP_MODEL_PATH:-}" ]] || fail "LLAMA_CPP_MODEL_PATH is not set"

if [[ $do_install -eq 1 ]]; then
    info "Installing composer dependencies"
    composer install --prefer-dist --no-progress --no-interaction
fi

[[ -d "$PROJECT_ROOT/vendor" ]] || fail "vendor/ is missing. Run with --install or run composer install first."

info "Running native llama.cpp integration tests"
./vendor/bin/pest tests/Integration/Runtime/LlamaCppNativeRuntimeIntegrationTest.php
ok "Native integration tests passed"

info "Running native runtime benchmark"
composer benchmark:llama-cpp-runtime
ok "Native runtime benchmark completed"

if [[ $skip_remote -eq 1 ]]; then
    warn "Skipping remote comparison by request"
    exit 0
fi

if [[ -z "${OLLAMA_MODEL:-}" ]]; then
    warn "OLLAMA_MODEL is not set. Skipping remote comparison."
    warn "Set OLLAMA_MODEL in $env_file or rerun setup with --ollama-model."
    exit 0
fi

info "Running php-agents comparison against ${OLLAMA_BASE_URL:-https://ollama:11434/v1}"
composer compare:llama-cpp-vs-ollama
ok "Remote comparison completed"