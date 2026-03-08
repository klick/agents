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
PLUGIN_FILE="$PLUGIN_ROOT/src/Plugin.php"
DASHBOARD_CONTROLLER="$PLUGIN_ROOT/src/controllers/DashboardController.php"
DASHBOARD_TEMPLATE="$PLUGIN_ROOT/src/templates/dashboard.twig"
CONTROL_TEMPLATE="$PLUGIN_ROOT/src/templates/control.twig"
ADOPTION_SERVICE="$PLUGIN_ROOT/src/services/AdoptionMetricsService.php"
CONTROL_SERVICE="$PLUGIN_ROOT/src/services/ControlPlaneService.php"

expect_fixed "public function actionAuthWhoami(): Response" "$API_CONTROLLER" "API exposes auth diagnostics endpoint action"
expect_fixed "public function actionAdoptionMetrics(): Response" "$API_CONTROLLER" "API exposes adoption metrics endpoint action"
expect_fixed "public function actionMetrics(): Response" "$API_CONTROLLER" "API exposes observability metrics endpoint action"
expect_fixed "public function actionConsumersLag(): Response" "$API_CONTROLLER" "API exposes consumer lag endpoint action"
expect_fixed "public function actionConsumersCheckpoint(): Response" "$API_CONTROLLER" "API exposes consumer checkpoint endpoint action"
expect_fixed "protected array|bool|int \$supportsCsrfValidation = false;" "$API_CONTROLLER" "API disables CSRF for machine endpoints"

expect_fixed "public function actionControlPolicies(): Response" "$API_CONTROLLER" "API exposes control policies endpoint action"
expect_fixed "public function actionControlPolicyUpsert(): Response" "$API_CONTROLLER" "API exposes control policy upsert endpoint action"
expect_fixed "public function actionControlApprovals(): Response" "$API_CONTROLLER" "API exposes control approvals endpoint action"
expect_fixed "public function actionControlApprovalRequest(): Response" "$API_CONTROLLER" "API exposes control approval request endpoint action"
expect_fixed "public function actionControlApprovalDecide(): Response" "$API_CONTROLLER" "API exposes control approval decide endpoint action"
expect_fixed "public function actionControlExecutions(): Response" "$API_CONTROLLER" "API exposes control executions endpoint action"
expect_fixed "public function actionControlPolicySimulate(): Response" "$API_CONTROLLER" "API exposes control policy simulator endpoint action"
expect_fixed "public function actionControlActionsExecute(): Response" "$API_CONTROLLER" "API exposes control execute endpoint action"
expect_fixed "public function actionControlAudit(): Response" "$API_CONTROLLER" "API exposes control audit endpoint action"

expect_fixed "public function actionWebhookDlqList(): Response" "$API_CONTROLLER" "API exposes webhook DLQ list endpoint action"
expect_fixed "public function actionWebhookDlqReplay(): Response" "$API_CONTROLLER" "API exposes webhook DLQ replay endpoint action"

expect_fixed "'/sync-state/lag'" "$API_CONTROLLER" "OpenAPI/capabilities include sync-state lag path"
expect_fixed "'/sync-state/checkpoint'" "$API_CONTROLLER" "OpenAPI/capabilities include sync-state checkpoint path"
expect_fixed "'/consumers/lag'" "$API_CONTROLLER" "OpenAPI/capabilities include consumers lag path"
expect_fixed "'/consumers/checkpoint'" "$API_CONTROLLER" "OpenAPI/capabilities include consumers checkpoint path"
expect_fixed "'/adoption/metrics'" "$API_CONTROLLER" "OpenAPI/capabilities include adoption metrics path"
expect_fixed "'/metrics'" "$API_CONTROLLER" "OpenAPI/capabilities include observability metrics path"
expect_fixed "'/control/actions/execute'" "$API_CONTROLLER" "OpenAPI/capabilities include control execute path"
expect_fixed "'/webhooks/dlq/replay'" "$API_CONTROLLER" "OpenAPI/capabilities include webhook replay path"
expect_fixed "syncstate:read" "$API_CONTROLLER" "Sync-state read scope is declared in API controller"
expect_fixed "syncstate:write" "$API_CONTROLLER" "Sync-state write scope is declared in API controller"
expect_fixed "adoption:read" "$API_CONTROLLER" "Adoption metrics scope is declared in API controller"
expect_fixed "metrics:read" "$API_CONTROLLER" "Observability metrics scope is declared in API controller"
expect_fixed "control:actions:execute" "$API_CONTROLLER" "Control execution scope is declared in API controller"
expect_fixed "entries:write:draft" "$API_CONTROLLER" "Entry draft write scope is declared in API controller"
expect_fixed "'entries:write' => 'Deprecated alias for \`entries:write:draft\`.'" "$API_CONTROLLER" "Deprecated entry draft scope alias is documented in API controller"
expect_fixed "webhooks:dlq:replay" "$API_CONTROLLER" "Webhook replay scope is declared in API controller"

expect_fixed "'agents/v1/adoption/metrics' => 'agents/api/adoption-metrics'" "$PLUGIN_FILE" "Plugin registers adoption metrics route"
expect_fixed "'agents/v1/metrics' => 'agents/api/metrics'" "$PLUGIN_FILE" "Plugin registers observability metrics route"
expect_fixed "'agents/v1/sync-state/checkpoint' => 'agents/api/consumers-checkpoint'" "$PLUGIN_FILE" "Plugin registers sync-state checkpoint route"
expect_fixed "'agents/v1/sync-state/lag' => 'agents/api/consumers-lag'" "$PLUGIN_FILE" "Plugin registers sync-state lag route"
expect_fixed "'agents/v1/consumers/checkpoint' => 'agents/api/consumers-checkpoint'" "$PLUGIN_FILE" "Plugin registers consumers checkpoint route"
expect_fixed "'agents/v1/consumers/lag' => 'agents/api/consumers-lag'" "$PLUGIN_FILE" "Plugin registers consumers lag route"
expect_fixed "'agents/v1/webhooks/dlq' => 'agents/api/webhook-dlq-list'" "$PLUGIN_FILE" "Plugin registers webhook DLQ list route"
expect_fixed "'agents/v1/webhooks/dlq/replay' => 'agents/api/webhook-dlq-replay'" "$PLUGIN_FILE" "Plugin registers webhook DLQ replay route"
expect_fixed "\$rules['agents/control/approvals'] = 'agents/dashboard/control';" "$PLUGIN_FILE" "Plugin registers Control approvals CP route"
expect_fixed "\$rules['agents/control/rules'] = 'agents/dashboard/control';" "$PLUGIN_FILE" "Plugin registers Control rules CP route"
expect_fixed "buildObservabilitySummary" "$DASHBOARD_CONTROLLER" "Dashboard controller builds observability summary for CP telemetry"
expect_fixed "public function actionExecuteApprovedControlAction(): Response" "$DASHBOARD_CONTROLLER" "Dashboard controller supports one-click approved execution action"
expect_fixed "approval runs the action immediately when threshold is met" "$DASHBOARD_CONTROLLER" "Dashboard controller enforces execute permission for auto-executing approvals"
expect_fixed "&& strtolower(trim(\$decisionStatus)) === 'approved'" "$DASHBOARD_CONTROLLER" "Dashboard controller auto-executes once final approval threshold is met"
expect_fixed "private function controlTabs(): array" "$DASHBOARD_CONTROLLER" "Dashboard controller defines Control sidebar tabs"
expect_fixed "private function resolveControlTab(): string" "$DASHBOARD_CONTROLLER" "Dashboard controller resolves active Control sidebar tab"
expect_fixed "formatControlActorLabel" "$DASHBOARD_CONTROLLER" "Dashboard controller resolves human-readable control actor labels"
expect_fixed "decidedByLabel" "$DASHBOARD_CONTROLLER" "Dashboard controller decorates approvals with approver labels"
expect_fixed "decorateControlItemsWithActionLabels" "$DASHBOARD_CONTROLLER" "Dashboard controller decorates control items with human-readable action labels"
expect_fixed "getUserByUsernameOrEmail" "$DASHBOARD_CONTROLLER" "Dashboard controller resolves CP users via supported username/email lookup"
expect_fixed "Telemetry Snapshot" "$DASHBOARD_TEMPLATE" "Dashboard readiness view exposes telemetry snapshot section"
expect_fixed "Runbook & Alert Guidance" "$DASHBOARD_TEMPLATE" "Dashboard readiness view exposes runbook guidance section"
expect_fixed "<h2 class=\"h4\">Approved</h2>" "$CONTROL_TEMPLATE" "Control CP template exposes unified approved section"
expect_fixed "Applied / Completed" "$CONTROL_TEMPLATE" "Control CP template exposes applied/completed terminal section"
expect_fixed "selectedItem: activeControlTab" "$CONTROL_TEMPLATE" "Control CP template binds Craft sidebar nav to active Control tab"
expect_fixed "approval.actionLabel" "$CONTROL_TEMPLATE" "Control CP template renders human-readable action labels"
expect_fixed "approval.decidedByLabel" "$CONTROL_TEMPLATE" "Control CP template shows human-readable approver labels"
expect_fixed "execute automatically once required approvals are complete" "$CONTROL_TEMPLATE" "Control CP template explains auto-execution on approval"
expect_fixed "elements/apply-draft" "$CONTROL_TEMPLATE" "Control CP template exposes apply-draft action from approved rows"
expect_fixed "getCpPosture()" "$ADOPTION_SERVICE" "Adoption metrics service uses existing security posture API"
expect_fixed "for (\$attempt = 0; \$attempt < 4; \$attempt++)" "$CONTROL_SERVICE" "Control approval decisions use optimistic concurrency retries"
expect_fixed "'secondaryDecisionBy' => null" "$CONTROL_SERVICE" "Control approval updates guard dual-approval second-decision races"
expect_fixed "entry.updatedraft" "$CONTROL_SERVICE" "Control execution service supports entry.updateDraft action"
expect_fixed "executeApprovedActionFromApprovalId" "$CONTROL_SERVICE" "Control execution service supports one-click execution from approved request"
expect_fixed "hasMultipleActiveCpUsers" "$CONTROL_SERVICE" "Control required-approvals logic degrades to single approval when only one active user exists"

echo "Control/consumer regression checks completed."
