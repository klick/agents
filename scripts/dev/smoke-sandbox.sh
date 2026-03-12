#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

BASE_URL="${1:-}"
TOKEN="${2:-}"

if [[ -z "$BASE_URL" || -z "$TOKEN" ]]; then
  echo "Usage: $0 <base-url> <api-token>"
  echo "Example: $0 https://agents-sandbox.ddev.site agents-local-token"
  exit 1
fi

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

request_status() {
  curl -sS -o /tmp/agents-smoke-body.$$ -w "%{http_code}" "$@"
}

echo "Running security regression checks..."
"$PLUGIN_ROOT/scripts/security-regression-check.sh" "$BASE_URL" "$TOKEN"

echo "Running contract alias checks..."
status="$(request_status "$BASE_URL/capabilities")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 from /capabilities, got $status"
fi
pass "capabilities alias reachable"

status="$(request_status "$BASE_URL/openapi.json")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 from /openapi.json, got $status"
fi
pass "openapi alias reachable"

echo "Sandbox smoke checks completed."
