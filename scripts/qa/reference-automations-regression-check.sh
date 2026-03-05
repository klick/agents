#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

fail() {
  echo "FAIL: $1"
  exit 1
}

expect_file() {
  local file="$1"
  local label="$2"
  [[ -f "$file" ]] || fail "$label missing: $file"
}

expect_fixed() {
  local needle="$1"
  local file="$2"
  local label="$3"
  grep -qF "$needle" "$file" || fail "$label"
}

expect_file "$PLUGIN_ROOT/docs/reference-automations.md" "Reference automations doc"
expect_file "$PLUGIN_ROOT/docs/reference-automations/fixtures/catalog-sync-checkpoint.json" "Catalog sync fixture"
expect_file "$PLUGIN_ROOT/docs/reference-automations/fixtures/return-approval-request.json" "Return approval request fixture"
expect_file "$PLUGIN_ROOT/docs/reference-automations/fixtures/return-approval-decide.json" "Return approval decision fixture"
expect_file "$PLUGIN_ROOT/docs/reference-automations/fixtures/return-action-execute.json" "Return action execute fixture"

expect_fixed "agents/v1/templates" "$PLUGIN_ROOT/src/Plugin.php" "Templates route registration missing"
expect_fixed "function actionTemplates" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Templates endpoint action missing"
expect_fixed "templates:read" "$PLUGIN_ROOT/src/controllers/ApiController.php" "templates:read scope missing in API controller"
expect_fixed "templates:read" "$PLUGIN_ROOT/src/services/SecurityPolicyService.php" "templates:read scope missing in security policy defaults"
expect_fixed "actionTemplateCatalog" "$PLUGIN_ROOT/src/console/controllers/AgentsController.php" "CLI template-catalog action missing"
expect_fixed "agents/template-catalog" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Capabilities command list missing agents/template-catalog"

expect_fixed "Template id: \`catalog-sync-loop\`" "$PLUGIN_ROOT/docs/reference-automations.md" "catalog-sync-loop reference doc missing"
expect_fixed "Template id: \`support-context-lookup\`" "$PLUGIN_ROOT/docs/reference-automations.md" "support-context-lookup reference doc missing"
expect_fixed "Template id: \`governed-return-approval-run\`" "$PLUGIN_ROOT/docs/reference-automations.md" "governed-return-approval-run reference doc missing"

while IFS= read -r fixture; do
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$fixture" >/dev/null
  done < <(find "$PLUGIN_ROOT/docs/reference-automations/fixtures" -name '*.json' -type f | sort)

echo "PASS: reference automation/template regression checks pass"
