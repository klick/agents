#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-}"
TOKEN="${2:-}"

if [[ -z "$BASE_URL" || -z "$TOKEN" ]]; then
  echo "Usage: $0 <base-url> <api-token>"
  echo "Example: $0 https://agents-sandbox.ddev.site agents-local-token"
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

updated_since="$(php -r 'echo gmdate("Y-m-d\\TH:i:s\\Z", time() - 172800);')"

status="$(request_status "$TMP_DIR/products-invalid.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/products?updatedSince=invalid-timestamp")"
if [[ "$status" != "400" ]]; then
  fail "Expected 400 for invalid products updatedSince, got $status"
fi
if [[ "$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["error"] ?? "";' "$TMP_DIR/products-invalid.json")" != "INVALID_REQUEST" ]]; then
  fail "Expected INVALID_REQUEST for invalid products updatedSince"
fi
pass "Products invalid updatedSince rejected"

status="$(request_status "$TMP_DIR/products-incremental-1.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/products?updatedSince=$updated_since&limit=2")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 for products incremental request, got $status"
fi
if [[ "$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["page"]["syncMode"] ?? "";' "$TMP_DIR/products-incremental-1.json")" != "incremental" ]]; then
  fail "Products incremental response missing page.syncMode=incremental"
fi
pass "Products incremental mode enabled"

products_updated_since="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["page"]["updatedSince"] ?? "";' "$TMP_DIR/products-incremental-1.json")"
products_next_cursor="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo is_string($j["page"]["nextCursor"] ?? null) ? $j["page"]["nextCursor"] : "";' "$TMP_DIR/products-incremental-1.json")"
if [[ -n "$products_next_cursor" ]]; then
  status="$(request_status "$TMP_DIR/products-incremental-2.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/products?cursor=$products_next_cursor&updatedSince=1999-01-01T00:00:00Z&limit=2")"
  if [[ "$status" != "200" ]]; then
    fail "Expected 200 for products cursor continuation, got $status"
  fi
  continued_updated_since="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["page"]["updatedSince"] ?? "";' "$TMP_DIR/products-incremental-2.json")"
  if [[ "$continued_updated_since" != "$products_updated_since" ]]; then
    fail "Products cursor continuation did not preserve cursor checkpoint updatedSince"
  fi
  pass "Products cursor precedence preserved over query updatedSince"
else
  echo "WARN: Products incremental dataset has no nextCursor; cursor precedence check skipped."
fi

status="$(request_status "$TMP_DIR/orders-incremental.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/orders?updatedSince=$updated_since&limit=1")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 for orders incremental request, got $status"
fi
if [[ "$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["meta"]["filters"]["lastDays"] ?? "");' "$TMP_DIR/orders-incremental.json")" != "0" ]]; then
  fail "Orders incremental request should default lastDays to 0 when omitted"
fi
pass "Orders incremental defaults verified"

status="$(request_status "$TMP_DIR/entries-incremental.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/entries?updatedSince=$updated_since&limit=1")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 for entries incremental request, got $status"
fi
if [[ "$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["page"]["syncMode"] ?? "";' "$TMP_DIR/entries-incremental.json")" != "incremental" ]]; then
  fail "Entries incremental response missing page.syncMode=incremental"
fi
pass "Entries incremental mode enabled"

status="$(request_status "$TMP_DIR/changes-invalid-types.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/changes?types=foo")"
if [[ "$status" != "400" ]]; then
  fail "Expected 400 for invalid /changes types value, got $status"
fi
if [[ "$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["error"] ?? "";' "$TMP_DIR/changes-invalid-types.json")" != "INVALID_REQUEST" ]]; then
  fail "Expected INVALID_REQUEST for invalid /changes types value"
fi
pass "Changes invalid types rejected"

status="$(request_status "$TMP_DIR/changes-1.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/changes?types=orders&updatedSince=$updated_since&limit=2")"
if [[ "$status" != "200" ]]; then
  fail "Expected 200 for /changes incremental request, got $status"
fi

if ! php -r '
  $j = json_decode(file_get_contents($argv[1]), true);
  if (!is_array($j)) { exit(1); }
  $types = $j["page"]["types"] ?? [];
  if (!is_array($types)) { exit(1); }
  $ok = count($types) === 1 && ($types[0] ?? null) === "orders";
  exit($ok ? 0 : 1);
' "$TMP_DIR/changes-1.json"; then
  fail "Changes response did not preserve normalized page.types for requested filter"
fi

if ! php -r '
  $j = json_decode(file_get_contents($argv[1]), true);
  if (!is_array($j)) { exit(1); }
  $data = $j["data"] ?? [];
  if (!is_array($data)) { exit(1); }
  foreach ($data as $item) {
    if (!is_array($item)) { exit(1); }
    foreach (["resourceType", "resourceId", "action", "updatedAt"] as $key) {
      if (!array_key_exists($key, $item)) { exit(1); }
    }
    if (!array_key_exists("snapshot", $item)) { exit(1); }
  }
  exit(0);
' "$TMP_DIR/changes-1.json"; then
  fail "Changes response contains malformed items"
fi
pass "Changes response item shape validated"

changes_next_cursor="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo is_string($j["page"]["nextCursor"] ?? null) ? $j["page"]["nextCursor"] : "";' "$TMP_DIR/changes-1.json")"
if [[ -n "$changes_next_cursor" ]]; then
  status="$(request_status "$TMP_DIR/changes-2.json" -H "Authorization: Bearer $TOKEN" "$BASE_URL/agents/v1/changes?cursor=$changes_next_cursor&types=products&limit=2")"
  if [[ "$status" != "200" ]]; then
    fail "Expected 200 for /changes cursor continuation, got $status"
  fi

  if ! php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($j)) { exit(1); }
    $types = $j["page"]["types"] ?? [];
    if (!is_array($types)) { exit(1); }
    $ok = count($types) === 1 && ($types[0] ?? null) === "orders";
    exit($ok ? 0 : 1);
  ' "$TMP_DIR/changes-2.json"; then
    fail "Changes cursor continuation did not keep cursor-bound types filter"
  fi
  pass "Changes cursor precedence preserved over query types"
else
  echo "WARN: Changes dataset has no nextCursor; cursor precedence check skipped."
fi

echo "Incremental regression checks completed."
