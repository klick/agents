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

expect_file "$PLUGIN_ROOT/src/services/StarterPackService.php" "Starter pack service"
STARTER_PACK_DOC="$PLUGIN_ROOT/docs/api/starter-packs.md"

expect_file "$STARTER_PACK_DOC" "Starter packs docs"

expect_fixed "'agents/v1/starter-packs' => 'agents/api/starter-packs'" "$PLUGIN_ROOT/src/Plugin.php" "Starter pack API route missing"

expect_fixed "function actionStarterPacks" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Starter pack API action missing"
expect_fixed "/starter-packs" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Starter packs OpenAPI/capabilities path missing"
expect_fixed "agents/starter-packs" "$PLUGIN_ROOT/src/controllers/ApiController.php" "Starter packs CLI command missing in capabilities"

expect_fixed "public function actionStarterPacks(): int" "$PLUGIN_ROOT/src/console/controllers/AgentsController.php" "Starter packs CLI action missing"
expect_fixed "'starter-packs' => array_merge(\$options, ['templateId', 'json'])" "$PLUGIN_ROOT/src/console/controllers/AgentsController.php" "Starter packs CLI options missing"

expect_fixed "catalog-sync-loop" "$PLUGIN_ROOT/src/services/StarterPackService.php" "Catalog sync starter pack missing"
expect_fixed "support-context-lookup" "$PLUGIN_ROOT/src/services/StarterPackService.php" "Support context starter pack missing"
expect_fixed "governed-return-approval-run" "$PLUGIN_ROOT/src/services/StarterPackService.php" "Governed return starter pack missing"
expect_fixed "governed-entry-draft-update" "$PLUGIN_ROOT/src/services/StarterPackService.php" "Governed entry draft starter pack missing"
expect_fixed "'curl'" "$PLUGIN_ROOT/src/services/StarterPackService.php" "curl runtime missing"
expect_fixed "'javascript'" "$PLUGIN_ROOT/src/services/StarterPackService.php" "javascript runtime missing"
expect_fixed "'python'" "$PLUGIN_ROOT/src/services/StarterPackService.php" "python runtime missing"

expect_fixed "AGENTS_TOKEN" "$PLUGIN_ROOT/src/services/StarterPackService.php" "AGENTS_TOKEN placeholder missing from snippets"
expect_fixed "BASE_URL" "$PLUGIN_ROOT/src/services/StarterPackService.php" "BASE_URL placeholder missing from snippets"
expect_fixed "SITE_URL" "$PLUGIN_ROOT/src/services/StarterPackService.php" "SITE_URL placeholder missing from snippets"

expect_fixed "GET /agents/v1/starter-packs" "$STARTER_PACK_DOC" "Starter packs endpoint docs missing"
expect_fixed "catalog-sync-loop" "$STARTER_PACK_DOC" "Catalog starter docs missing"
expect_fixed "support-context-lookup" "$STARTER_PACK_DOC" "Support starter docs missing"
expect_fixed "governed-return-approval-run" "$STARTER_PACK_DOC" "Governed return starter docs missing"
expect_fixed "governed-entry-draft-update" "$STARTER_PACK_DOC" "Governed entry draft starter docs missing"
expect_fixed 'craft agents/starter-packs' "$STARTER_PACK_DOC" "Starter packs CLI docs missing"
expect_fixed ".starterPack.runtimes.curl.snippet" "$STARTER_PACK_DOC" "curl snippet extraction docs missing"
expect_fixed ".starterPack.runtimes.javascript.snippet" "$STARTER_PACK_DOC" "javascript snippet extraction docs missing"
expect_fixed ".starterPack.runtimes.python.snippet" "$STARTER_PACK_DOC" "python snippet extraction docs missing"

echo "PASS: starter pack regression checks pass"
