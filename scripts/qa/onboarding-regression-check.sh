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
ICON_PARTIAL_DIR="$PLUGIN_ROOT/src/templates/_partials/onboarding-icons"

expect_fixed "OnboardingStateService::class" "$PLUGIN_FILE" "Plugin registers onboarding state service component"
expect_fixed "'agents/start' => 'agents/dashboard/start'" "$PLUGIN_FILE" "Plugin registers the onboarding start CP route"
expect_fixed "public string \$schemaVersion = '0.28.0';" "$PLUGIN_FILE" "Plugin schema version includes the onboarding and workflow migrations"
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
expect_fixed "normalizeTimestampValueForDatabase" "$ONBOARDING_SERVICE" "Onboarding state service normalizes database timestamp writes"
expect_fixed "normalizeTimestampsForDatabase" "$ONBOARDING_SERVICE" "Onboarding state service sanitizes onboarding state rows before persistence"
expect_fixed "Create your first <em>managed account</em>" "$START_TEMPLATE" "Onboarding start template renders the welcome headline"
expect_fixed "Your first managed account is ready" "$START_TEMPLATE" "Onboarding start template renders the finished-stage headline"
expect_fixed "actionInput('agents/dashboard/create-onboarding-credential')" "$START_TEMPLATE" "Onboarding start template posts account creation through the dedicated onboarding action"
expect_fixed "actionInput('agents/dashboard/dismiss-onboarding')" "$START_TEMPLATE" "Onboarding start template exposes onboarding dismissal"
expect_fixed "data-onboarding-copy-token" "$START_TEMPLATE" "Onboarding start template exposes one-click token copy"
expect_fixed "data-onboarding-download-env" "$START_TEMPLATE" "Onboarding start template exposes one-click env download"
expect_fixed "machine identity your agent or other external runtime" "$START_TEMPLATE" "Onboarding create copy frames managed accounts as agent/runtime identities"
expect_fixed "You set the boundary. It works inside the access and approval rules you define." "$START_TEMPLATE" "Onboarding create copy reassures operators about the governed boundary"
expect_fixed "Connect your first agent" "$START_TEMPLATE" "Onboarding ready state uses agent-first runtime wording"
expect_fixed "It only works inside the boundary you define here" "$START_TEMPLATE" "Onboarding ready copy keeps the token reveal boundary-scoped"
expect_fixed "You set the boundary first. The external runtime works inside it." "$START_TEMPLATE" "Onboarding welcome copy explains the operator-first trust model"
expect_fixed "onboardingPreviewParam = 'onboardingPreview'" "$START_TEMPLATE" "Onboarding start template preserves the preview query param"
expect_fixed "agents-onboarding-shell--compact" "$START_TEMPLATE" "Onboarding start template defines a compact create/ready layout modifier"
expect_fixed "agents-onboarding-hero-field" "$START_TEMPLATE" "Onboarding start template renders the SVG avatar background field"
expect_fixed "avatarVariantCount = 216" "$START_TEMPLATE" "Onboarding start template targets the full 216-icon onboarding field"
expect_fixed "avatarExtraVariants = [" "$START_TEMPLATE" "Onboarding start template appends extra pictograms to stabilize the last row"
expect_fixed "agents-onboarding-hero-avatar--extra" "$START_TEMPLATE" "Onboarding start template marks the appended hero pictograms explicitly"
expect_fixed "data-onboarding-hero-avatar" "$START_TEMPLATE" "Onboarding start template tags hero avatars for pulse simulation"
expect_fixed "'figma-' ~ '%02d'|format(iconIndex)" "$START_TEMPLATE" "Onboarding start template renders the Figma icon field through the shared variant sequence"
expect_fixed "agents/_partials/onboarding-avatar" "$START_TEMPLATE" "Onboarding start template renders avatars from the shared include wrapper"
expect_fixed "onboardingStage == 'welcome'" "$START_TEMPLATE" "Onboarding start template keys the hero treatment off the welcome stage"
expect_fixed "agents-onboarding-hero-read-burst-a" "$START_TEMPLATE" "Onboarding start template defines the first onboarding hero read-burst animation"
expect_fixed "agents-onboarding-hero-read-burst-b" "$START_TEMPLATE" "Onboarding start template defines the second onboarding hero read-burst animation"
expect_fixed "agents-onboarding-pulse-color" "$START_TEMPLATE" "Onboarding start template animates the hero pictogram color token back to the base state"
expect_fixed "selectedAvatars = shuffle(heroAvatars).slice(0, Math.min(4, heroAvatars.length));" "$START_TEMPLATE" "Onboarding start template randomizes the four active welcome avatars"
expect_fixed "agents/_partials/onboarding-icons/" "$AVATAR_PARTIAL" "Onboarding avatar partial delegates to the icon partial directory"
expect_fixed "<svg" "$ICON_PARTIAL_DIR/figma-01.twig" "First onboarding icon partial exists"
expect_fixed "<svg" "$ICON_PARTIAL_DIR/figma-216.twig" "Last onboarding icon partial exists"

echo "All onboarding regression checks passed."
