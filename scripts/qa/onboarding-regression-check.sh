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

PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
DASHBOARD_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
ONBOARDING_SERVICE="$PLUGIN_ROOT/src/services/OnboardingStateService.php"
ONBOARDING_MIGRATION="$PLUGIN_ROOT/src/migrations/m260321_100000_add_onboarding_state_table.php"
START_TEMPLATE="$PLUGIN_ROOT/src/templates/start.twig"
AVATAR_PARTIAL="$PLUGIN_ROOT/src/templates/_partials/onboarding-avatar.twig"

expect_fixed "OnboardingStateService::class" "$PLUGIN_FILE" "Plugin registers onboarding state service component"
expect_fixed "'agents/start' => 'agents/dashboard/start'" "$PLUGIN_FILE" "Plugin registers the onboarding start CP route"
expect_fixed "public string \$schemaVersion = '0.28.0';" "$PLUGIN_FILE" "Plugin bumps schema version for onboarding migration"
expect_fixed "public function actionStart(): Response" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes the onboarding start action"
expect_fixed "public function actionCreateOnboardingCredential(): Response" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes the onboarding account-create action"
expect_fixed "public function actionDismissOnboarding(): Response" "$DASHBOARD_CONTROLLER" "Dashboard controller exposes onboarding dismissal"
expect_fixed "private function resolveCpHomeUrl(): string" "$DASHBOARD_CONTROLLER" "Dashboard controller centralizes onboarding-aware CP home routing"
expect_fixed "private function defaultOnboardingScopes(): array" "$DASHBOARD_CONTROLLER" "Dashboard controller defines starter scopes for onboarding accounts"
expect_fixed "private function buildOnboardingCredentialHandle(string \$displayName): string" "$DASHBOARD_CONTROLLER" "Dashboard controller derives unique onboarding account handles"
expect_fixed "markFirstAccountCreated" "$DASHBOARD_CONTROLLER" "Dashboard controller records first-account onboarding progress"
expect_fixed "self::ONBOARDING_PREVIEW_PARAM" "$DASHBOARD_CONTROLLER" "Dashboard controller recognizes the onboarding preview query param"
expect_fixed "class OnboardingStateService extends Component" "$ONBOARDING_SERVICE" "Onboarding state service class exists"
expect_fixed "agents_onboarding_state" "$ONBOARDING_MIGRATION" "Onboarding migration defines runtime state table"
expect_fixed "firstSuccessfulAuthAt" "$ONBOARDING_MIGRATION" "Onboarding migration stores first successful auth timestamp"
expect_fixed "dismissedAt" "$ONBOARDING_MIGRATION" "Onboarding migration stores dismissal timestamp"
expect_fixed "normalizePreviewStage" "$ONBOARDING_SERVICE" "Onboarding state service normalizes preview-stage aliases"
expect_fixed "buildPreviewState" "$ONBOARDING_SERVICE" "Onboarding state service supports non-persistent preview states"
expect_fixed "backfillStateFromCredentials" "$ONBOARDING_SERVICE" "Onboarding state service backfills progress from managed credential usage"
expect_fixed "Create your first <em>managed account</em>" "$START_TEMPLATE" "Onboarding start template renders the welcome headline"
expect_fixed "Your first managed account is ready" "$START_TEMPLATE" "Onboarding start template renders the finished-stage headline"
expect_fixed "actionInput('agents/dashboard/create-onboarding-credential')" "$START_TEMPLATE" "Onboarding start template posts account creation through the dedicated onboarding action"
expect_fixed "actionInput('agents/dashboard/dismiss-onboarding')" "$START_TEMPLATE" "Onboarding start template exposes onboarding dismissal"
expect_fixed "data-onboarding-copy-token" "$START_TEMPLATE" "Onboarding start template exposes one-click token copy"
expect_fixed "data-onboarding-download-env" "$START_TEMPLATE" "Onboarding start template exposes one-click env download"
expect_fixed "onboardingPreviewParam = 'onboardingPreview'" "$START_TEMPLATE" "Onboarding start template preserves the preview query param"
expect_fixed "agents-onboarding-hero-grid" "$START_TEMPLATE" "Onboarding start template renders the SVG avatar background grid"
expect_fixed "agents/_partials/onboarding-avatar" "$START_TEMPLATE" "Onboarding start template renders avatars from the shared SVG partial"
expect_fixed "variant == 'bot'" "$AVATAR_PARTIAL" "Onboarding avatar partial exposes the bot variant"
expect_fixed "variant == 'shield'" "$AVATAR_PARTIAL" "Onboarding avatar partial exposes the shield variant"
expect_fixed "variant == 'spark'" "$AVATAR_PARTIAL" "Onboarding avatar partial exposes the spark variant"

echo "All onboarding regression checks passed."
