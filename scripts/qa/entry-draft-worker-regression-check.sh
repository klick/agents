#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
WORKER_DIR="$PLUGIN_ROOT/examples/workers/entry-draft-governed"
DOC_FILE="$PLUGIN_ROOT/docs/workflows/governed-entry-drafts.md"
DOCS_INDEX="$PLUGIN_ROOT/docs/workflows/index.md"
README_FILE="$WORKER_DIR/README.md"
DOCS_CONFIG="$PLUGIN_ROOT/docs/.vitepress/config.mts"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

[[ -f "$WORKER_DIR/worker.mjs" ]] || fail "Missing entry draft worker example"
[[ -f "$WORKER_DIR/.env.example" ]] || fail "Missing entry draft worker env example"
[[ -f "$WORKER_DIR/run-worker.sh" ]] || fail "Missing entry draft worker runner"
[[ -f "$README_FILE" ]] || fail "Missing entry draft worker README"
[[ -f "$DOC_FILE" ]] || fail "Missing governed entry drafts docs page"

node --check "$WORKER_DIR/worker.mjs" >/dev/null || fail "Entry draft worker is not valid Node syntax"

grep -q '/control/approvals/request' "$WORKER_DIR/worker.mjs" || fail "Worker does not call approval request endpoint"
grep -q '/control/actions/execute' "$WORKER_DIR/worker.mjs" || fail "Worker does not call execute endpoint"
grep -q 'MODE=request' "$WORKER_DIR/.env.example" || fail "Worker env example is missing request mode"
grep -q 'APPROVAL_ID' "$WORKER_DIR/.env.example" || fail "Worker env example is missing approval id"
grep -q 'control:approvals:request' "$README_FILE" || fail "Worker README is missing approval scope guidance"
grep -q 'entries:write:draft' "$README_FILE" || fail "Worker README is missing draft-write scope guidance"
grep -q '/workflows/governed-entry-drafts' "$DOCS_INDEX" || fail "Workflow index is missing governed entry drafts link"
grep -q '/workflows/governed-entry-drafts' "$DOCS_CONFIG" || fail "Docs sidebar is missing governed entry drafts link"
grep -q 'PLUGIN_AGENTS_WRITES_EXPERIMENTAL=true' "$DOC_FILE" || fail "Workflow docs are missing writes experimental guidance"

pass "entry draft worker example and docs are present"
