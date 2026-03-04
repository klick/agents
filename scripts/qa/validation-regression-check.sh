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
SECURITY_POLICY_SERVICE="$PLUGIN_ROOT/src/services/SecurityPolicyService.php"
README_FILE="$PLUGIN_ROOT/README.md"

expect_fixed "private function invalidQueryResponse(array \$errors): Response" "$API_CONTROLLER" "ApiController exposes shared invalid-query response helper"
expect_fixed "private function validateProjectionAndFilterQueryParams(): array" "$API_CONTROLLER" "ApiController validates projection/filter query contracts"
expect_fixed "private function validateIntegerQueryParam(string \$name, int \$min, ?int \$max): ?string" "$API_CONTROLLER" "ApiController validates integer query parameters deterministically"
expect_fixed "private function validateEnumQueryParam(string \$name, array \$allowedValues): ?string" "$API_CONTROLLER" "ApiController validates enum query parameters deterministically"
expect_fixed "private function validatePatternQueryParam(string \$name, string \$pattern, string \$label): ?string" "$API_CONTROLLER" "ApiController validates token/pattern query parameters deterministically"
expect_fixed "private function buildRuntimeProfileMetadata(array \$config): array" "$API_CONTROLLER" "ApiController exposes shared runtime profile metadata helper"

expect_fixed 'return $this->invalidQueryResponse($errors);' "$API_CONTROLLER" "List endpoints route invalid query params through deterministic invalidQueryResponse"
expect_fixed 'Provide exactly one identifier: `id` or `number`.' "$API_CONTROLLER" "Order show endpoint enforces deterministic identifier contract"
expect_fixed 'Provide exactly one identifier: `id` or `slug`.' "$API_CONTROLLER" "Entry show endpoint enforces deterministic identifier contract"
expect_fixed '`filter` must include at least one valid `path:value` expression.' "$API_CONTROLLER" "Filter validation contract message is present"
expect_fixed '`fields` must include at least one field path.' "$API_CONTROLLER" "Fields validation contract message is present"

expect_fixed "private function resolveEnvironmentProfile(string \$environment): array" "$SECURITY_POLICY_SERVICE" "SecurityPolicyService resolves environment profile"
expect_fixed "private function inferEnvironmentProfile(string \$environment): string" "$SECURITY_POLICY_SERVICE" "SecurityPolicyService infers environment profile"
expect_fixed "private function normalizeEnvironmentProfile(string \$profile): ?string" "$SECURITY_POLICY_SERVICE" "SecurityPolicyService normalizes environment profile aliases"
expect_fixed "private function resolveBooleanRuntimeSetting(" "$SECURITY_POLICY_SERVICE" "SecurityPolicyService resolves boolean settings with profile defaults"
expect_fixed "private function resolvePositiveIntegerRuntimeSetting(" "$SECURITY_POLICY_SERVICE" "SecurityPolicyService resolves integer settings with profile defaults"
expect_fixed "environmentProfile" "$SECURITY_POLICY_SERVICE" "SecurityPolicyService runtime config includes environment profile metadata"

expect_fixed '`PLUGIN_AGENTS_ENV_PROFILE`' "$README_FILE" "README documents environment profile configuration"

echo "Validation regression checks completed."
