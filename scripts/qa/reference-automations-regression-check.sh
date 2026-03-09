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

FIXTURES_DIR="$PLUGIN_ROOT/examples/reference-automations/fixtures"
STARTER_PACK_DOC="$PLUGIN_ROOT/docs/api/starter-packs.md"

expect_file "$STARTER_PACK_DOC" "Starter packs doc"
expect_file "$FIXTURES_DIR/catalog-sync-checkpoint.json" "Catalog sync fixture"
expect_file "$FIXTURES_DIR/return-approval-request.json" "Return approval request fixture"
expect_file "$FIXTURES_DIR/return-approval-decide.json" "Return approval decision fixture"
expect_file "$FIXTURES_DIR/return-action-execute.json" "Return action execute fixture"
expect_file "$FIXTURES_DIR/entry-update-draft-execute.json" "Entry draft execute fixture"

expect_fixed "agents/v1/templates" "$PLUGIN_ROOT/src/Plugin.php" "Templates route registration missing"
expect_fixed "function actionTemplates" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Templates endpoint action missing"
expect_fixed "templates:read" "$PLUGIN_ROOT/src/controllers/ApiController.php" "templates:read scope missing in API controller"
expect_fixed "templates:read" "$PLUGIN_ROOT/src/services/SecurityPolicyService.php" "templates:read scope missing in security policy defaults"
expect_fixed "actionTemplateCatalog" "$PLUGIN_ROOT/src/console/controllers/AgentsController.php" "CLI template-catalog action missing"
expect_fixed "agents/template-catalog" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Capabilities command list missing agents/template-catalog"

expect_fixed "catalog-sync-loop" "$STARTER_PACK_DOC" "catalog-sync-loop starter pack docs missing"
expect_fixed "support-context-lookup" "$STARTER_PACK_DOC" "support-context-lookup starter pack docs missing"
expect_fixed "governed-return-approval-run" "$STARTER_PACK_DOC" "governed-return-approval-run starter pack docs missing"
expect_fixed "governed-entry-draft-update" "$STARTER_PACK_DOC" "governed-entry-draft-update starter pack docs missing"
expect_fixed "\"actionType\": \"entry.updateDraft\"" "$FIXTURES_DIR/entry-update-draft-execute.json" "entry.updateDraft fixture action type missing"

while IFS= read -r fixture; do
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$fixture" >/dev/null
done < <(find "$FIXTURES_DIR" -name '*.json' -type f | sort)

echo "PASS: reference automation/template regression checks pass"
