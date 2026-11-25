#!/usr/bin/env bash
# P5 parity gate: capture Laravel responses and compare against the Python baseline.
#
# Usage: ./tests/parity/capture.sh [--no-compare]
#
# Exits 0 when the Laravel capture matches the Python baseline; 1 when
# differences exist (expected until Laravel implements the missing routes —
# those gaps are the parity signal the cutover decision relies on).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

PHP_BIN="${PHP_BIN:-php}"
LARAVEL_DIR="$REPO_ROOT/laravel"
ENV_FILE="$LARAVEL_DIR/.env"
COMPARE=1
if [ "${1:-}" = "--no-compare" ]; then COMPARE=0; fi

# Convert MSYS/Unix path to Windows native path so the native PHP binary can open it.
to_win() {
    if command -v cygpath >/dev/null 2>&1; then
        cygpath -w -- "$1"
    else
        printf '%s' "$1"
    fi
}
LAV_WIN="$(to_win "$LARAVEL_DIR")"

run_artisan() {
    "$PHP_BIN" "$LAV_WIN/artisan" "$@"
}

echo "==> Ensuring Laravel environment"
if [ ! -f "$ENV_FILE" ]; then
    cp "$LARAVEL_DIR/.env.example" "$ENV_FILE"
fi
# Generate an app key so encrypted sessions/cookies work (required by the kernel).
run_artisan key:generate --force >/dev/null 2>&1 || true

echo "==> Preparing database"
run_artisan migrate --force >/dev/null 2>&1 || true

echo "==> Running parity capture (php artisan parity:capture)"
run_artisan parity:capture

echo "==> Publishing capture to tests/parity/baseline/laravel.json"
cp "$LARAVEL_DIR/storage/app/parity/laravel.json" "$REPO_ROOT/tests/parity/baseline/laravel.json"

if [ "$COMPARE" -eq 1 ]; then
    echo "==> Comparing against Python baseline"
    python3 tools/compare_parity.py tests/parity/baseline/laravel.json
fi
