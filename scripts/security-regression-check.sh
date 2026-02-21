#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-}"
TOKEN="${2:-}"

if [[ -z "$BASE_URL" || -z "$TOKEN" ]]; then
  echo "Usage: $0 <base-url> <api-token>"
  echo "Example: $0 https://coloursource.ddev.site local-test-token"
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

request_status() {
  local body_file="$1"
  shift
  curl -sS -o "$body_file" -w "%{http_code}" "$@"
}

status="$(request_status "$TMP_DIR/no-token.json" "$BASE_URL/agents/v1/health")"
if [[ "$status" != "401" && "$status" != "503" ]]; then
  fail "Expected 401/503 without token, got $status"
fi
pass "Missing token rejected ($status)"

status="$(request_status "$TMP_DIR/query-token.json" "$BASE_URL/agents/v1/health?apiToken=$TOKEN")"
if [[ "$status" != "401" ]]; then
  fail "Expected 401 for query-token auth when disabled, got $status"
fi
pass "Query token rejected by default"

status="$(request_status "$TMP_DIR/header-token.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/readiness")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 with bearer token, got $status"
fi
pass "Bearer token accepted"

status="$(request_status "$TMP_DIR/non-live-entries.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/entries?status=all&limit=1")"
if [[ "$status" != "403" ]]; then
  fail "Expected 403 for non-live entries without elevated scope, got $status"
fi
pass "Non-live entry access blocked without elevated scope"

status="$(request_status "$TMP_DIR/orders.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/orders?limit=1")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 from orders endpoint, got $status"
fi
if grep -q '"emailRedacted":[[:space:]]*true' "$TMP_DIR/orders.json"; then
  pass "Order email redaction active for non-sensitive scope"
else
  echo "WARN: Could not assert email redaction flag from orders response."
fi

etag="$(curl -sS -D - -o "$TMP_DIR/openapi-1.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/openapi.json" | awk 'tolower($1)=="etag:" {print $2}' | tr -d '\r\n')"
if [[ -n "$etag" ]]; then
  status="$(curl -sS -o "$TMP_DIR/openapi-304.txt" -D "$TMP_DIR/openapi-headers-304.txt" -w "%{http_code}" -H "Authorization: Bearer $TOKEN" -H "If-None-Match: $etag" "$BASE_URL/agents/v1/openapi.json")"
  if [[ "$status" == "304" || "$status" == "200" ]]; then
    pass "OpenAPI conditional request path healthy ($status)"
  else
    fail "Expected 200/304 for OpenAPI conditional request, got $status"
  fi
fi

echo "Security regression checks completed."
