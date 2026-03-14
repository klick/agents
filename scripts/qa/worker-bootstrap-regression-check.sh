#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
WORKER_DIR="$PLUGIN_ROOT/examples/workers/node-bootstrap"
DOC_FILE="$PLUGIN_ROOT/docs/get-started/first-worker.md"
DOCS_INDEX="$PLUGIN_ROOT/docs/get-started/index.md"
README_FILE="$WORKER_DIR/README.md"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

[[ -f "$WORKER_DIR/worker.mjs" ]] || fail "Missing worker example"
[[ -f "$WORKER_DIR/.env.example" ]] || fail "Missing worker env example"
[[ -f "$WORKER_DIR/run-worker.sh" ]] || fail "Missing worker runner"
[[ -f "$README_FILE" ]] || fail "Missing worker README"
[[ -f "$DOC_FILE" ]] || fail "Missing first-worker docs page"

node --check "$WORKER_DIR/worker.mjs" >/dev/null || fail "Worker example is not valid Node syntax"

grep -q '/auth/whoami' "$WORKER_DIR/worker.mjs" || fail "Worker example does not call /auth/whoami"
grep -q '/health' "$WORKER_DIR/worker.mjs" || fail "Worker example does not call /health"
grep -q '/readiness' "$WORKER_DIR/worker.mjs" || fail "Worker example does not call /readiness"
grep -q 'AGENTS_TOKEN' "$WORKER_DIR/.env.example" || fail "Worker env example is missing AGENTS_TOKEN"
grep -q 'cron' "$README_FILE" || fail "Worker README is missing cron guidance"
grep -q 'one-time revealed token' "$README_FILE" || fail "Worker README is missing one-time token guidance"
grep -q '/get-started/first-worker' "$DOCS_INDEX" || fail "Get Started index is missing the first worker link"
grep -q 'node-bootstrap' "$DOC_FILE" || fail "First worker docs are missing the example path reference"
grep -q 'token is only shown once' "$DOC_FILE" || fail "First worker docs are missing one-time token guidance"

pass "worker bootstrap example and docs are present"
