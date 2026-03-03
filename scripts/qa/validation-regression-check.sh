#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

fail() {
  echo "FAIL: $1"
  exit 1
}

pass() {
  echo "PASS: $1"
}

expect_fixed() {
  local needle="$1"
  local file="$2"
  local description="$3"
  if grep -Fq "$needle" "$file"; then
    pass "$description"
    return
  fi
  fail "$description (missing: $needle in $file)"
}

API_CONTROLLER="$PLUGIN_ROOT/src/controllers/ApiController.php"

expect_fixed "private function invalidQueryResponse(array \$errors): Response" "$API_CONTROLLER" "ApiController exposes shared invalid-query response helper"
expect_fixed "private function validateProjectionAndFilterQueryParams(): array" "$API_CONTROLLER" "ApiController validates projection/filter query contracts"
expect_fixed "private function validateIntegerQueryParam(string \$name, int \$min, ?int \$max): ?string" "$API_CONTROLLER" "ApiController validates integer query parameters deterministically"
expect_fixed "private function validateEnumQueryParam(string \$name, array \$allowedValues): ?string" "$API_CONTROLLER" "ApiController validates enum query parameters deterministically"
expect_fixed "private function validatePatternQueryParam(string \$name, string \$pattern, string \$label): ?string" "$API_CONTROLLER" "ApiController validates token/pattern query parameters deterministically"

expect_fixed 'return $this->invalidQueryResponse($errors);' "$API_CONTROLLER" "List endpoints route invalid query params through deterministic invalidQueryResponse"
expect_fixed 'Provide exactly one identifier: `id` or `number`.' "$API_CONTROLLER" "Order show endpoint enforces deterministic identifier contract"
expect_fixed 'Provide exactly one identifier: `id` or `slug`.' "$API_CONTROLLER" "Entry show endpoint enforces deterministic identifier contract"
expect_fixed '`filter` must include at least one valid `path:value` expression.' "$API_CONTROLLER" "Filter validation contract message is present"
expect_fixed '`fields` must include at least one field path.' "$API_CONTROLLER" "Fields validation contract message is present"

echo "Validation regression checks completed."
