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

PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
API_CONTROLLER="$PLUGIN_ROOT/src/controllers/ApiController.php"
CLI_CONTROLLER="$PLUGIN_ROOT/src/console/controllers/AgentsController.php"
DASHBOARD_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
CREDENTIALS_TEMPLATE="$PLUGIN_ROOT/src/templates/credentials.twig"
LIFECYCLE_SERVICE="$PLUGIN_ROOT/src/services/LifecycleGovernanceService.php"
README_FILE="$PLUGIN_ROOT/README.md"
LIFECYCLE_DOC="$PLUGIN_ROOT/docs/agent-lifecycle-governance.md"
VITEPRESS_LIFECYCLE_DOC="$PLUGIN_ROOT/.docs/site/docs/troubleshooting/agent-lifecycle-governance.md"

expect_file "$LIFECYCLE_SERVICE" "Lifecycle governance service"
expect_file "$LIFECYCLE_DOC" "Lifecycle governance doc"
expect_file "$VITEPRESS_LIFECYCLE_DOC" "VitePress lifecycle governance doc"

expect_fixed "'lifecycleGovernanceService' => LifecycleGovernanceService::class" "$PLUGIN_FILE" "Plugin lifecycle service component missing"
expect_fixed "public function getLifecycleGovernanceService(): LifecycleGovernanceService" "$PLUGIN_FILE" "Plugin lifecycle service getter missing"
expect_fixed "'agents/v1/lifecycle' => 'agents/api/lifecycle'" "$PLUGIN_FILE" "Lifecycle route missing"

expect_fixed "public function actionLifecycle(): Response" "$API_CONTROLLER" "Lifecycle API action missing"
expect_fixed "['method' => 'GET', 'path' => '/lifecycle', 'requiredScopes' => ['lifecycle:read']]" "$API_CONTROLLER" "Lifecycle capabilities contract missing"
expect_fixed "'/lifecycle' => ['get' => [" "$API_CONTROLLER" "Lifecycle OpenAPI contract missing"
expect_fixed "'lifecycle.snapshot'" "$API_CONTROLLER" "Lifecycle schema catalog contract missing"
expect_fixed "'lifecycle:read' => 'Read agent lifecycle governance snapshot" "$API_CONTROLLER" "Lifecycle scope catalog missing"

expect_fixed "'lifecycle-report'" "$CLI_CONTROLLER" "Lifecycle CLI options binding missing"
expect_fixed "public function actionLifecycleReport(): int" "$CLI_CONTROLLER" "Lifecycle CLI action missing"

expect_fixed "\$plugin->getLifecycleGovernanceService()->getSnapshot()" "$DASHBOARD_CONTROLLER" "Dashboard controller lifecycle snapshot wiring missing"
expect_fixed "'lifecycleSummary' => \$lifecycleSummary" "$DASHBOARD_CONTROLLER" "Dashboard lifecycle summary payload missing"
expect_fixed "'lifecycleByCredentialId' => \$lifecycleByCredentialId" "$DASHBOARD_CONTROLLER" "Dashboard per-agent lifecycle payload missing"

expect_fixed "Lifecycle Governance" "$CREDENTIALS_TEMPLATE" "Credentials CP lifecycle summary section missing"

expect_fixed "GET /lifecycle" "$README_FILE" "README lifecycle endpoint missing"
expect_fixed "lifecycle:read" "$README_FILE" "README lifecycle scope missing"
expect_fixed "craft agents/lifecycle-report" "$README_FILE" "README lifecycle CLI command missing"

echo "PASS: lifecycle governance regression checks pass"
