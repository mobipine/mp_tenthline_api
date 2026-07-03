#!/usr/bin/env bash
# Start N PaddleOCR sidecar instances on consecutive ports (default 2 from 8765).
# Usage: ./run.sh [instances] [base_port]
#
# Prefer several single-worker instances over `uvicorn --workers N`: Paddle
# crashes inside forked uvicorn worker processes on macOS. Point the Laravel
# side at all instances via PADDLEOCR_URLS and set PADDLEOCR_CONCURRENCY to
# the instance count so pages are OCR'd in parallel.
set -euo pipefail

cd "$(dirname "$0")"

INSTANCES="${1:-2}"
BASE_PORT="${2:-8765}"
UVICORN=".venv/bin/uvicorn"

if [[ ! -x "$UVICORN" ]]; then
    echo "error: $UVICORN not found — create the venv first (python3 -m venv .venv && .venv/bin/pip install -r requirements.txt)" >&2
    exit 1
fi

PIDS=()
cleanup() {
    for pid in "${PIDS[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
}
trap cleanup EXIT INT TERM

# Cap per-instance threads so concurrent instances don't oversubscribe the
# CPU (Paddle's spin-waiting threads degrade ~6x when oversubscribed).
CORES=$(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo 4)
THREADS=$(( CORES / INSTANCES ))
[[ $THREADS -lt 1 ]] && THREADS=1

URLS=()
for ((i = 0; i < INSTANCES; i++)); do
    PORT=$((BASE_PORT + i))
    CPU_THREADS="$THREADS" OMP_NUM_THREADS="$THREADS" OPENBLAS_NUM_THREADS="$THREADS" \
        "$UVICORN" main:app --host 127.0.0.1 --port "$PORT" &
    PIDS+=($!)
    URLS+=("http://127.0.0.1:${PORT}")
done

IFS=,
echo
echo "Started ${INSTANCES} PaddleOCR instance(s). Configure Laravel with:"
echo "  PADDLEOCR_URLS=${URLS[*]}"
echo "  PADDLEOCR_CONCURRENCY=${INSTANCES}"
echo
unset IFS

wait
