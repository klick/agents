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

request_status_with_headers() {
  local headers_file="$1"
  local body_file="$2"
  shift 2
  curl -sS -D "$headers_file" -o "$body_file" -w "%{http_code}" "$@"
}

status="$(request_status_with_headers "$TMP_DIR/no-token.headers" "$TMP_DIR/no-token.json" "$BASE_URL/agents/v1/health")"
if [[ "$status" != "401" && "$status" != "503" ]]; then
  fail "Expected 401/503 without token, got $status"
fi
pass "Missing token rejected ($status)"
if grep -qi '^X-Request-Id:' "$TMP_DIR/no-token.headers"; then
  pass "Missing-token response contains X-Request-Id"
else
  fail "Missing-token response did not include X-Request-Id"
fi
if grep -Eq '"requestId"[[:space:]]*:[[:space:]]*"[^"]+"' "$TMP_DIR/no-token.json" \
  && grep -Eq '"status"[[:space:]]*:[[:space:]]*(401|503)' "$TMP_DIR/no-token.json" \
  && grep -Eq '"error"[[:space:]]*:[[:space:]]*"(UNAUTHORIZED|SERVER_MISCONFIGURED)"' "$TMP_DIR/no-token.json"; then
  pass "Error body contains stable requestId/status/error fields"
else
  fail "Error body did not match expected requestId/status/error schema"
fi

status="$(request_status "$TMP_DIR/query-token.json" "$BASE_URL/agents/v1/health?apiToken=$TOKEN")"
if [[ "$status" != "401" ]]; then
  fail "Expected 401 for query-token auth when disabled, got $status"
fi
pass "Query token rejected by default"

status="$(request_status_with_headers "$TMP_DIR/header-token.headers" "$TMP_DIR/header-token.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/readiness")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 with bearer token, got $status"
fi
pass "Bearer token accepted"
if grep -qi '^X-Request-Id:' "$TMP_DIR/header-token.headers"; then
  pass "Authorized response contains X-Request-Id"
else
  fail "Authorized response did not include X-Request-Id"
fi

status="$(request_status "$TMP_DIR/capabilities.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/capabilities")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 from capabilities endpoint, got $status"
fi
if grep -q '"tokenConfigured":[[:space:]]*true' "$TMP_DIR/capabilities.json"; then
  pass "Capabilities reports token/credential configuration"
else
  echo "WARN: Could not confirm tokenConfigured=true from capabilities response."
fi

status="$(request_status "$TMP_DIR/post-health.json" -X POST -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/health")"
if [[ "$status" != "405" && "$status" != "400" ]]; then
  fail "Expected 405/400 for non-read request to read-only endpoint, got $status"
fi
pass "Non-read requests rejected ($status)"

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
