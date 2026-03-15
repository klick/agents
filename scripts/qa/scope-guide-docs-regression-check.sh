#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
GUIDE_FILE="$PLUGIN_ROOT/docs/api/scope-guide.md"
AUTH_FILE="$PLUGIN_ROOT/docs/api/auth-and-scopes.md"
API_INDEX_FILE="$PLUGIN_ROOT/docs/api/index.md"
DOCS_CONFIG="$PLUGIN_ROOT/docs/.vitepress/config.mts"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

[[ -f "$GUIDE_FILE" ]] || fail "Missing scope guide docs page"

grep -q '^# Scope Guide' "$GUIDE_FILE" || fail "Scope guide title is missing"
grep -q '`adoption:read`' "$GUIDE_FILE" || fail "Scope guide is missing adoption scope guidance"
grep -q '`metrics:read`' "$GUIDE_FILE" || fail "Scope guide is missing metrics scope guidance"
grep -q '`lifecycle:read`' "$GUIDE_FILE" || fail "Scope guide is missing lifecycle scope guidance"
grep -q 'What it means in plain language' "$GUIDE_FILE" || fail "Scope guide is missing operator-facing explanation framing"
grep -q '/api/scope-guide' "$AUTH_FILE" || fail "Auth & Scopes docs are missing the scope guide link"
grep -q '/api/scope-guide' "$API_INDEX_FILE" || fail "API index is missing the scope guide link"
grep -q "/api/scope-guide" "$DOCS_CONFIG" || fail "Docs sidebar is missing the scope guide link"

pass "scope guide docs are present and linked"
